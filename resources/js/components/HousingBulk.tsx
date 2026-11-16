import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useAdminMutation } from '../lib/adminMutation';
export default function HousingBulk({ baseUrl }: { baseUrl: string }) {
    const [open, setOpen] = useState(false),
        [form, setForm] = useState({
            block_code: '',
            block_name: '',
            first: 1,
            count: 24,
            beds: 12,
            capacity: 12,
            kind: 'normal',
        });
    const mutation = useAdminMutation({
        onSaved: async () => {
            setOpen(false);
            router.reload();
        },
    });
    return (
        <section className="editor-panel">
            <button className="button" disabled={mutation.busy} onClick={() => setOpen(!open)}>
                Toplu blok / koğuş oluştur
            </button>
            {open && (
                <form
                    className="definition-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        void mutation.submit(baseUrl + '/toplu', form);
                    }}
                >
                    <p>
                        Yeni blok, numaraları artan koğuşlar ve 1’den başlayan ranzalar tek işlemde
                        oluşturulur.
                    </p>
                    <div className="user-fields">
                        {[
                            ['block_code', 'Blok kodu'],
                            ['block_name', 'Blok adı'],
                        ].map(([key, label]) => (
                            <label className="form-field" key={key}>
                                <span>{label}</span>
                                <input
                                    required
                                    maxLength={40}
                                    value={form[key as 'block_code']}
                                    onChange={(e) => setForm({ ...form, [key]: e.target.value })}
                                />
                            </label>
                        ))}
                        {[
                            ['first', 'İlk koğuş numarası', 9999],
                            ['count', 'Koğuş sayısı', 500],
                            ['beds', 'Her koğuştaki ranza', 500],
                            ['capacity', 'Her koğuştaki kapasite', 500],
                        ].map(([key, label, max]) => (
                            <label className="form-field" key={key}>
                                <span>{label}</span>
                                <input
                                    type="number"
                                    required
                                    min={['first', 'count'].includes(String(key)) ? 1 : 0}
                                    max={Number(max)}
                                    value={form[key as 'first']}
                                    onChange={(e) => setForm({ ...form, [key]: Number(e.target.value) })}
                                />
                            </label>
                        ))}
                    </div>
                    <label className="form-field">
                        <span>Koğuş türü</span>
                        <select
                            value={form.kind}
                            onChange={(e) => setForm({ ...form, kind: e.target.value })}
                        >
                            <option value="normal">Normal</option>
                            <option value="gecici">Geçici</option>
                            <option value="ozel">Özel</option>
                        </select>
                    </label>
                    {mutation.error && (
                        <p role="alert" className="form-error">
                            {mutation.error}
                        </p>
                    )}
                    <button className="button button--primary" disabled={mutation.busy}>
                        Blok ve koğuşları oluştur
                    </button>
                </form>
            )}
        </section>
    );
}
