import type { InputHTMLAttributes } from 'react';
const folderInput: InputHTMLAttributes<HTMLInputElement> & { webkitdirectory: string } = {
    webkitdirectory: '',
};
import { useForm } from '@inertiajs/react';
import { portalUrl } from '../lib/portalUrl';
export default function AdministrationUploads({
    directory,
    scope,
    busy,
}: {
    directory: string;
    scope: string;
    busy: boolean;
}) {
    const form = useForm({ path: directory, scope, files: [] as File[], paths: [] as string[] });
    function select(files: FileList | null) {
        const list = Array.from(files ?? []);
        form.setData({ ...form.data, files: list, paths: list.map((f) => f.webkitRelativePath || f.name) });
    }
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(portalUrl('/yonetim/dosya-yukle'), {
                    forceFormData: true,
                    onSuccess: () => form.reset('files', 'paths'),
                });
            }}
        >
            <label>
                Çoklu dosya <input type="file" multiple onChange={(e) => select(e.target.files)} />
            </label>
            <label>
                Klasör yükle{' '}
                <input type="file" multiple {...folderInput} onChange={(e) => select(e.target.files)} />
            </label>
            <p>{form.data.files.length} dosya seçildi · toplam en fazla 20 MB</p>
            <button className="button" disabled={busy || form.processing || !form.data.files.length}>
                Yükle
            </button>
            {Object.values(form.errors).map((e) => (
                <p role="alert" key={e}>
                    {e}
                </p>
            ))}
        </form>
    );
}
