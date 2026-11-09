@extends('layouts.portal')
@section('title', 'Kurum İçi Sohbet')
@section('page-title', 'Sohbet')
@section('content')
<div style="display: grid; grid-template-columns: 280px 1fr; gap: 20px; height: calc(100vh - 140px); min-height: 500px;">
    
    <!-- Sol Panel: Kullanıcı Listesi -->
    <aside class="panel" style="padding: 15px; display: flex; flex-direction: column; gap: 15px; height: 100%; overflow: hidden;">
        <div class="panel-heading" style="padding: 0; margin-bottom: 5px;">
            <h2>Sohbet Odaları</h2>
        </div>
        
        <a class="nav-link @if(!$receiver) active @endif" href="{{ route('operations.index', 'sohbet') }}" style="display: flex; justify-content: space-between; align-items: center; padding: 10px; border-radius: 6px; text-decoration: none; font-weight: bold; background: @if(!$receiver) var(--primary-color, #312e81) @else transparent @endif; color: @if(!$receiver) white @else inherit @endif;">
            <span>📢 Genel Sohbet</span>
        </a>
        
        <div style="flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 5px; padding-right: 5px;">
            <p class="eyebrow" style="margin-bottom: 5px; margin-top: 10px;">Personel Listesi</p>
            @foreach($users as $user)
                @php
                    $isOnline = $user->last_activity_at && $user->last_activity_at->gt(now()->subMinutes(5));
                    $unreadCount = \App\Models\OperationRecord::where('module_code', 'sohbet')->visible()->where('institution_id', auth()->user()->institution_id)
                        ->where('record_type', 'Özel mesaj')
                        ->where('created_by', $user->id)
                        ->where('data->Alıcı', (string) auth()->id())
                        ->where('data->Okundu durumu', 'okunmadi')
                        ->count();
                @endphp
                <a href="{{ route('operations.index', ['sohbet', 'receiver_id' => $user->id]) }}" 
                   style="display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; border-radius: 6px; text-decoration: none; color: inherit; transition: background 0.2s; background: @if($receiver && $receiver->id === $user->id) #e2e8f0 @else transparent @endif;"
                   onmouseover="this.style.background='#f1f5f9'" 
                   onmouseout="this.style.background='@if($receiver && $receiver->id === $user->id) #e2e8f0 @else transparent @endif'">
                    
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <!-- Çevrimiçi durum noktası -->
                        <span style="height: 8px; width: 8px; border-radius: 50%; display: inline-block; background-color: @if($isOnline) #22c55e @else #9ca3af @endif;" title="@if($isOnline) Çevrimiçi @else Çevrimdışı @endif"></span>
                        <span style="font-size: 0.9rem; @if($unreadCount > 0) font-weight: bold; @endif">{{ $user->full_name }}</span>
                    </div>
                    
                    @if($unreadCount > 0)
                        <span style="background: #ef4444; color: white; border-radius: 12px; padding: 1px 6px; font-size: 0.75rem; font-weight: bold;">{{ $unreadCount }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </aside>

    <!-- Sağ Panel: Mesajlaşma Alanı -->
    <section class="panel" style="display: flex; flex-direction: column; height: 100%; overflow: hidden; padding: 0;">
        <!-- Mesajlaşma Başlığı -->
        <div style="padding: 15px 20px; border-bottom: 1px solid var(--border-color, #e5e7eb); background: #f8fafc; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h3 style="margin: 0; font-size: 1.1rem;">
                    @if($receiver)
                        💬 {{ $receiver->full_name }} ile Sohbet
                    @else
                        📢 Genel Sohbet Kanalı
                    @endif
                </h3>
                <span class="muted-text" style="font-size: 0.8rem;">
                    @if($receiver)
                        {{ $receiver->department?->name ?? 'Personel' }} · 
                        @if($receiver->last_activity_at && $receiver->last_activity_at->gt(now()->subMinutes(5)))
                            <span style="color: #22c55e; font-weight: bold;">Çevrimiçi</span>
                        @else
                            Son görülme: {{ $receiver->last_activity_at?->format('d.m.Y H:i') ?? 'Bilinmiyor' }}
                        @endif
                    @else
                        Tüm personel ile ortak mesajlaşma alanı.
                    @endif
                </span>
            </div>
        </div>

        <!-- Mesaj Listesi -->
        <div style="flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 15px; background: #fafafa;">
            @forelse($messages as $msg)
                @php
                    $isMe = (string) $msg->created_by === (string) auth()->id();
                    $senderName = $isMe ? 'Siz' : ($msg->creator?->full_name ?? 'Bilinmeyen Kullanıcı');
                @endphp
                
                @if(!$isMe && $receiver && ($msg->data['Okundu durumu'] ?? '') !== 'okundu')
                    <form hidden data-chat-read action="{{ route('operations.chat.read', $msg) }}" method="post">@csrf</form>
                @endif
                <div style="display: flex; flex-direction: column; align-items: @if($isMe) flex-end @else flex-start @endif; max-width: 75%; align-self: @if($isMe) flex-end @else flex-start @endif;">
                    <div style="display: flex; gap: 8px; margin-bottom: 3px; font-size: 0.75rem;" class="muted-text">
                        <strong>{{ $senderName }}</strong>
                        <span>{{ $msg->starts_at?->format('H:i') ?? '—' }}</span>
                        @if($isMe && $receiver)
                            <span style="color: @if(($msg->data['Okundu durumu'] ?? '') === 'okundu') #3b82f6 @else #9ca3af @endif;" title="Okundu Bilgisi">
                                @if(($msg->data['Okundu durumu'] ?? '') === 'okundu') ✓✓ @else ✓ @endif
                            </span>
                        @endif
                    </div>
                    <div style="padding: 10px 14px; border-radius: 12px; font-size: 0.9rem; line-height: 1.4; 
                                background: @if($isMe) var(--primary-color, #312e81) @else #e2e8f0 @endif; 
                                color: @if($isMe) white @else inherit @endif; 
                                border-bottom-right-radius: @if($isMe) 2px @else 12px @endif;
                                border-bottom-left-radius: @if($isMe) 12px @else 2px @endif;
                                box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                        {!! nl2br(e($msg->body)) !!}
                    </div>
                </div>
            @empty
                <div style="margin: auto; text-align: center;" class="muted-text">
                    <p style="font-size: 2.5rem; margin-bottom: 10px;">💬</p>
                    <p>Henüz mesaj yok. İlk mesajı siz gönderin!</p>
                </div>
            @endforelse
        </div>

        <!-- Mesaj Gönderme Formu -->
        <div style="padding: 15px 20px; border-top: 1px solid var(--border-color, #e5e7eb); background: white;">
            <form method="post" action="{{ route('operations.chat.send') }}" style="display: flex; gap: 10px; align-items: flex-end;">
                @csrf
                @if($receiver)
                    <input type="hidden" name="receiver_id" value="{{ $receiver->id }}">
                @endif
                <textarea name="body" placeholder="Mesajınızı yazın..." required rows="2" 
                          style="flex: 1; resize: none; border: 1px solid var(--border-color, #e5e7eb); border-radius: 8px; padding: 10px; font-size: 0.9rem; outline: none;"
                          onkeydown="if(event.keyCode === 13 && !event.shiftKey) { event.preventDefault(); this.form.submit(); }"></textarea>
                <button class="primary-button" type="submit" style="padding: 10px 20px; height: 42px;">Gönder</button>
            </form>
        </div>
    </section>
</div>
@endsection
