export const fieldLibrary = [
  { type: 'short_text', name: 'Short Text', icon: '🔤', desc: 'Teks pendek', defaultConfig: { label: 'Nama', placeholder: 'Masukkan nama', required: true } },
  { type: 'long_text', name: 'Long Text', icon: '📝', desc: 'Paragraf', defaultConfig: { label: 'Alasan', placeholder: '', required: false } },
  { type: 'email', name: 'Email', icon: '📧', desc: 'Email', defaultConfig: { label: 'Email', required: true } },
  { type: 'phone', name: 'Phone', icon: '📱', desc: 'No HP', defaultConfig: { label: 'No HP', required: false } },
  { type: 'number', name: 'Number', icon: '🔢', desc: 'Angka', defaultConfig: { label: 'Jumlah', required: false } },
  { type: 'date', name: 'Date/Time', icon: '📅', desc: 'Tanggal', defaultConfig: { label: 'Tanggal', required: false } },
  { type: 'dropdown', name: 'Dropdown', icon: '▼', desc: 'Pilihan', defaultConfig: { label: 'Kategori', options: ['Opsi 1','Opsi 2'], required: true } },
  { type: 'radio', name: 'Radio', icon: '◉', desc: 'Satu pilihan', defaultConfig: { label: 'Jenis Kelamin', options: ['Laki-laki','Perempuan'], required: true } },
  { type: 'checkbox', name: 'Checkbox', icon: '☑', desc: 'Multi pilih', defaultConfig: { label: 'Minat', options: ['Buku','Seminar','Workshop'], required: false } },
  { type: 'yesno', name: 'Yes/No', icon: '✅', desc: 'Ya/Tidak', defaultConfig: { label: 'Setuju?', required: true } },
  { type: 'file', name: 'File Upload', icon: '📎', desc: 'Upload', defaultConfig: { label: 'Upload Dokumen', required: false, maxSizeMB: 5 } },
  { type: 'rating', name: 'Rating', icon: '⭐', desc: 'Rating', defaultConfig: { label: 'Rating', max: 5, required: false } },
  { type: 'consent', name: 'Consent', icon: '📜', desc: 'Persetujuan', defaultConfig: { label: 'Saya setuju dengan syarat', required: true } },
  { type: 'hidden', name: 'Hidden', icon: '👁️‍🗨️', desc: 'Hidden field', defaultConfig: { label: 'campaign_id', value: '' } }
];
