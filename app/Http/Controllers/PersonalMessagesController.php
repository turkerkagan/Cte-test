<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLog;
use App\Services\Operations\PrivateFileCleanup;
use App\Services\Workflows\PersonalMessageAccess;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class PersonalMessagesController extends Controller
{
    public function index(Request $r)
    {
        $a = app(WorkflowAccess::class)->authorize($r->user(), 'mesajlar');
        $d = $r->validate(['box' => ['nullable', 'in:inbox,sent'], 'page' => ['nullable', 'integer', 'min:1']]);
        $box = $d['box'] ?? 'inbox';
        $page = (int) ($d['page'] ?? 1);
        $column = $box === 'inbox' ? 'recipient' : 'sender';
        $q = DB::table('personal_messages')->where($column.'_id', $a->id)->where($column.'_deleted', false);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->offset(($page - 1) * 80)->limit(80)->get(['id', 'sender_name', 'recipient_name', 'subject', 'read_at', 'created_at']);
        $counts = $rows->isEmpty() ? collect() : DB::table('message_attachments')
            ->whereIn('message_id', $rows->pluck('id'))->whereNull('deleted_at')->where('expires_at', '>=', now())
            ->selectRaw('message_id, COUNT(*) as attachment_count')->groupBy('message_id')->pluck('attachment_count', 'message_id');
        $rows->each(function ($message) use ($counts) {
            $message->attachments = (int) ($counts[$message->id] ?? 0);
        });
        $users = DB::table('users')->where('is_active', true)->where('id', '<>', $a->id)->orderBy('full_name')->get(['id', 'full_name', 'username']);

        return $this->noStore(Inertia::render('PersonalMessages', ['applicationName' => config('app.name'), 'title' => 'Kişisel mesajlar', 'baseUrl' => route('personal-messages.index'), 'logoutUrl' => route('logout'), 'box' => $box, 'page' => $page, 'total' => $total, 'messages' => $rows, 'users' => $users, 'canWrite' => app(WorkflowAccess::class)->canWrite($a, 'mesajlar'), 'attachmentRetryMinutes' => max(0, (int) config('document_tools.attachment_retry_minutes', 5))])->toResponse($r));
    }

    public function read(Request $r)
    {
        $a = app(WorkflowAccess::class)->authorize($r->user(), 'mesajlar');
        $m = app(PersonalMessageAccess::class)->visibleTo($a)->where('id', $r->route('message'))->first();
        abort_if(! $m, 404);
        $m->body = Crypt::decryptString($m->body);
        $m->attachments = DB::table('message_attachments')->where('message_id', $m->id)->whereNull('deleted_at')->where('expires_at', '>=', now())->get(['id', 'name', 'size', 'expires_at', 'recipient_downloaded_at']);

        return response()->json(['message' => $m])->header('Cache-Control', 'private, no-store');
    }

    public function mutate(Request $r)
    {
        $a = app(WorkflowAccess::class)->authorize($r->user(), 'mesajlar');
        $d = $r->validate(['action' => ['required', 'in:send,delete,read'], 'message' => ['required_unless:action,send', 'nullable', 'integer', 'min:1'], 'recipient_id' => ['required_if:action,send', 'nullable', 'integer', 'min:1'], 'subject' => ['required_if:action,send', 'nullable', 'string', 'max:255'], 'body' => ['nullable', 'string', 'max:20000'], 'request_id' => ['required_if:action,send', 'nullable', 'uuid'], 'attachment' => ['nullable', 'file', 'max:25600', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,txt,zip,rar']]);
        $path = null;
        if ($d['action'] === 'send') {
            app(WorkflowAccess::class)->authorize($a, 'mesajlar', 'yazma');
            abort_unless(trim($d['body'] ?? '') !== '' || $r->hasFile('attachment'), 422, 'Mesaj veya ek seçin.');
            if ($r->hasFile('attachment')) {
                $f = $r->file('attachment');
                abort_unless(in_array(strtolower($f->getClientOriginalExtension()), ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt', 'zip', 'rar'], true), 422);
                $path = $f->storeAs('message-attachments', Str::uuid().'.'.strtolower($f->getClientOriginalExtension()), 'local');
                abort_if(! $path, 503, 'Ek kaydedilemedi.');
            }
        }
        try {
            $result = DB::transaction(function () use ($r, $a, $d, $path) {
                DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
                $a = app(WorkflowAccess::class)->authorize($a, 'mesajlar', $d['action'] === 'send' ? 'yazma' : 'giris', true);
                if ($d['action'] !== 'send') {
                    $m = DB::table('personal_messages')->where('id', $d['message'])->lockForUpdate()->first();
                    abort_unless($m && ($m->sender_id === $a->id || $m->recipient_id === $a->id), 404);
                    $values = $d['action'] === 'delete' ? [$m->sender_id === $a->id ? 'sender_deleted' : 'recipient_deleted' => true] : ($m->recipient_id === $a->id && ! $m->recipient_deleted ? ['read_at' => $m->read_at ?? now()] : []);
                    if ($values) {
                        DB::table('personal_messages')->where('id', $m->id)->update($values + ['updated_at' => now()]);
                    }

                    return ['saved' => true];
                }$recipient = DB::table('users')->where('id', $d['recipient_id'])->where('id', '<>', $a->id)->where('is_active', true)->first();
                abort_if(! $recipient, 422, 'Aktif başka bir alıcı seçin.');
                $old = DB::table('personal_messages')->where('request_id', $d['request_id'])->first();
                if ($old) {
                    abort_unless($old->sender_id === $a->id && $old->recipient_id === (int) $d['recipient_id'] && $old->subject === trim($d['subject']) && Crypt::decryptString($old->body) === ($d['body'] ?? ''), 409);

                    return ['saved' => true, 'id' => $old->id, 'duplicate' => true];
                }$id = DB::table('personal_messages')->insertGetId(['sender_id' => $a->id, 'recipient_id' => $recipient->id, 'sender_name' => $a->full_name ?: $a->username, 'recipient_name' => $recipient->full_name ?: $recipient->username, 'subject' => trim($d['subject']), 'body' => Crypt::encryptString($d['body'] ?? ''), 'request_id' => $d['request_id'], 'created_at' => now(), 'updated_at' => now()]);
                if ($path) {
                    $f = $r->file('attachment');
                    DB::table('message_attachments')->insert(['message_id' => $id, 'path' => $path, 'name' => mb_substr(basename($f->getClientOriginalName()), 0, 180), 'mime' => $f->getMimeType(), 'size' => $f->getSize(), 'expires_at' => now()->addDays(config('document_tools.attachment_days')), 'created_at' => now(), 'updated_at' => now()]);
                }app(AuditLog::class)->record($a, 'personal_message', $id, 'sent', ['attachment' => $path !== null]);

                return ['saved' => true, 'id' => $id];
            }, 3);
        } catch (\Throwable$e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }if (($result['duplicate'] ?? false) && $path) {
            Storage::disk('local')->delete($path);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $r)
    {
        $a = app(WorkflowAccess::class)->authorize($r->user(), 'mesajlar');

        return DB::transaction(function () use ($r, $a) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $a = app(WorkflowAccess::class)->authorize($a, 'mesajlar', 'giris', true);
            $file = DB::table('message_attachments')->where('id', $r->route('attachment'))->lockForUpdate()->first();
            abort_if(! $file || $file->deleted_at || $file->expires_at < now()->format('Y-m-d H:i:s'), 404);
            $m = app(PersonalMessageAccess::class)->visibleTo($a)->where('id', $file->message_id)->first();
            abort_unless($m !== null, 404);
            $disk = Storage::disk('local');
            if (! $disk->exists($file->path)) {
                DB::table('message_attachments')->where('id', $file->id)->update(['deleted_at' => now(), 'updated_at' => now()]);

                return response('', 404);
            }
            $bytes = $disk->get($file->path);
            abort_if($bytes === null, 503);
            $recipientDownload = $m->recipient_id === $a->id && config('document_tools.delete_attachment_after_recipient_download');
            $retryMinutes = max(0, (int) config('document_tools.attachment_retry_minutes', 5));
            $delete = $recipientDownload && $retryMinutes === 0;
            $updates = ['download_count' => $file->download_count + 1, 'last_downloaded_at' => now(), 'deleted_at' => $delete ? now() : null, 'updated_at' => now()];
            if ($recipientDownload && $file->recipient_downloaded_at === null) {
                $updates['recipient_downloaded_at'] = now();
                // First recipient download sets an immutable retry deadline; subsequent requests never extend it.
                $updates['expires_at'] = min($file->expires_at, now()->addMinutes($retryMinutes)->toDateTimeString());
            }
            DB::table('message_attachments')->where('id', $file->id)->update($updates);
            if ($delete) {
                $cleanup = app(PrivateFileCleanup::class);
                $cleanup->enqueue([$file->path]);
                $cleanup->afterCommit();
            }$response = response($bytes, 200, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
            $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition('attachment', $file->name, 'attachment'));

            return $response;
        }, 3);
    }
}
