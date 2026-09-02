export const blockLibrary = [
  { type: 'profile', name: 'Profile', icon: '👤', desc: 'Foto, nama, bio', defaultData: { name: 'Nama Anda', bio: 'Deskripsi singkat', avatar: '👤' } },
  { type: 'text', name: 'Text', icon: '📝', desc: 'Paragraf', defaultData: { content: 'Tulis sesuatu...' } },
  { type: 'button', name: 'Button', icon: '🔘', desc: 'CTA link', defaultData: { label: 'Kunjungi', url: 'https://example.com', style: 'primary' } },
  { type: 'image', name: 'Image', icon: '🖼️', desc: 'Gambar', defaultData: { url: '', alt: '' } },
  { type: 'video', name: 'Video', icon: '🎥', desc: 'Embed video', defaultData: { url: 'https://youtube.com/watch?v=...' } },
  { type: 'social', name: 'Social Links', icon: '🌐', desc: 'Sosial media', defaultData: { links: [{ platform: 'Instagram', url: '' }] } },
  { type: 'form', name: 'Form', icon: '📋', desc: 'Embed form', defaultData: { form_id: null } },
  { type: 'qr', name: 'QR Code', icon: '🔲', desc: 'Tampilkan QR', defaultData: { qr_id: null } },
  { type: 'divider', name: 'Divider', icon: '➖', desc: 'Pemisah', defaultData: {} },
  { type: 'product', name: 'Product/Card', icon: '🛍️', desc: 'Produk', defaultData: { name: 'Produk', price: 'Rp 0', image: '🛍️' } },
  { type: 'announcement', name: 'Announcement', icon: '📢', desc: 'Pengumuman', defaultData: { text: 'Pengumuman penting!' } }
];
