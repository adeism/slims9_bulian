<?php
/**
 * Sample FAQ content — replace it with your library's real answers
 *
 * This file is the "one page" of FAQ content the chat answers from. The
 * plugin uses it as a fallback, so a fresh install already replies to common
 * questions. To take ownership of the content:
 *
 *     cp plugins/chatbot/Support/faq.sample.php config/chatbot.faq.php
 *
 * Then edit `config/chatbot.faq.php` — the plugin prefers that file and never
 * touches this sample again. Nothing here is written to the database, so
 * keeping the real answers in `config/` (not in git) is intentional.
 *
 * Every entry is an array:
 *
 *   'id'          stable identifier, unique (used in the reply meta)
 *   'question'    the title shown in "sering ditanyakan" lists (Indonesian)
 *   'question_en' optional English title
 *   'keywords'    words people actually type, both languages; phrases work
 *                 ("jam buka", "opening hours"). A one-word typo is tolerated.
 *   'answer'      plain text, 2–4 short sentences, no markup
 *   'answer_en'   optional English answer
 *   'link'        optional URL shown as "baca selengkapnya"
 *
 * All values below are EXAMPLES. Replace addresses, numbers and policies with
 * your own before going live.
 *
 * @license GPLv3
 */

return [
    [
        'id' => 'hours',
        'question' => 'Jam buka perpustakaan',
        'question_en' => 'Opening hours',
        'keywords' => [
            'jam buka', 'jam', 'buka', 'tutup', 'jam layanan', 'hari libur',
            'opening hours', 'open', 'close', 'closed', 'weekend', 'sabtu', 'minggu',
        ],
        'answer' => 'Senin–Jumat 08.00–16.00, Sabtu 09.00–12.00. Minggu dan hari libur nasional tutup. Layanan sirkulasi berhenti 15 menit sebelum tutup.',
        'answer_en' => 'Monday to Friday 08.00–16.00, Saturday 09.00–12.00. Closed on Sundays and public holidays. Circulation stops 15 minutes before closing.',
    ],
    [
        'id' => 'address',
        'question' => 'Alamat dan lokasi perpustakaan',
        'question_en' => 'Address and location',
        'keywords' => [
            'alamat', 'lokasi', 'di mana', 'dimana', 'tempat', 'gedung', 'peta',
            'address', 'location', 'where', 'map', 'building',
        ],
        'answer' => 'Jl. Contoh Raya No. 1, Kelurahan Contoh, Kota Contoh 12345. Depan halte bus Contoh; parkir motor di sisi timur gedung.',
        'answer_en' => 'Jl. Contoh Raya No. 1, Contoh District, Contoh City 12345. Opposite the Contoh bus stop; motorbike parking on the east side.',
    ],
    [
        'id' => 'phone',
        'question' => 'Nomor telepon dan WhatsApp',
        'question_en' => 'Phone number and WhatsApp',
        'keywords' => [
            'telepon', 'telpon', 'tlp', 'telp', 'nomor', 'kontak', 'whatsapp', 'wa',
            'phone', 'call', 'contact', 'number', 'mobile',
        ],
        'answer' => 'Telepon (021) 1234567, WhatsApp 0812-3456-7890 (hanya pesan, dibalas pada jam kerja).',
        'answer_en' => 'Phone (021) 1234567, WhatsApp +62 812-3456-7890 (messages only, answered during office hours).',
    ],
    [
        'id' => 'email',
        'question' => 'Alamat surel (email)',
        'question_en' => 'E-mail address',
        'keywords' => [
            'email', 'surel', 'e-mail', 'e-mail address', 'email address', 'alamat surel',
            'surat elektronik', 'mengirim email', 'mail', 'write to',
        ],
        'answer' => 'pustaka@contoh.go.id — sertakan nomor anggota pada subjek bila menanyakan pinjaman atau denda.',
        'answer_en' => 'pustaka@contoh.go.id — include your member number in the subject for loan or fine questions.',
    ],
    [
        'id' => 'membership',
        'question' => 'Cara menjadi anggota',
        'question_en' => 'How to become a member',
        'keywords' => [
            'daftar anggota', 'cara daftar', 'jadi anggota', 'kartu anggota', 'keanggotaan',
            'syarat', 'registrasi', 'membership', 'register', 'sign up', 'member card', 'join',
            'menjadi anggota', 'cara mendaftar', 'anggota baru', 'become a member', 'how to join', 'new member',
        ],
        'answer' => 'Datang ke meja layanan dengan KTP/KK dan mengisi formulir. Kartu anggota dicetak hari itu juga. Untuk pendaftaran kolektif (sekolah/instansi), kirim surat permohonan ke alamat surel kami.',
        'answer_en' => 'Come to the service desk with your ID card and fill in the form. The card is printed the same day. For group registration (school or institution), send a request letter to our e-mail.',
        // Optional: shown as a "read more" link under the answer. A relative
        // path works on any install; an absolute URL must be https.
        'link' => 'index.php?p=member',
    ],
    [
        'id' => 'membership-fee',
        'question' => 'Biaya dan masa berlaku keanggotaan',
        'question_en' => 'Membership fee and validity',
        'keywords' => [
            'biaya', 'bayar', 'harga', 'tarif', 'gratis', 'masa berlaku', 'berapa lama anggota',
            'biaya keanggotaan', 'biaya pendaftaran', 'iuran', 'fee', 'cost', 'price', 'free',
            'validity', 'expire', 'membership fee', 'registration fee',
        ],
        'answer' => 'Pendaftaran gratis. Kartu berlaku 2 tahun dan dapat diperpanjang tanpa biaya selama tidak ada tunggakan denda.',
        'answer_en' => 'Registration is free. The card is valid for 2 years and can be renewed at no cost as long as there are no outstanding fines.',
    ],
    [
        'id' => 'loan-limit',
        'question' => 'Berapa buku yang boleh dipinjam dan berapa lama',
        'question_en' => 'How many items can I borrow and for how long',
        'keywords' => [
            'berapa buku', 'boleh pinjam', 'batas pinjam', 'lama pinjam', 'berapa lama',
            'pinjam berapa', 'kuota', 'limit', 'how many', 'how long', 'borrow', 'loan period',
        ],
        'answer' => 'Anggota dapat meminjam 3 eksemplar untuk 7 hari. Koleksi referensi dan majalah terbaru hanya untuk dibaca di tempat.',
        'answer_en' => 'Members may borrow 3 items for 7 days. Reference works and the latest magazines are for in-library use only.',
    ],
    [
        'id' => 'renew',
        'question' => 'Cara memperpanjang pinjaman',
        'question_en' => 'How to renew a loan',
        'keywords' => [
            'perpanjang', 'perpanjangan', 'renew', 'extend', 'extension', 'tambah hari',
            'masa pinjam', 'memperpanjang',
        ],
        'answer' => 'Perpanjangan dapat dilakukan satu kali bila buku tidak dipesan anggota lain. Sebutkan nomor pinjaman atau kode buku, atau tanyakan "perpanjang pinjaman saya" di chat.',
        'answer_en' => 'One renewal is allowed when nobody else has reserved the item. Quote the loan number or item code, or ask "renew my loan" in the chat.',
    ],
    [
        'id' => 'fines',
        'question' => 'Denda keterlambatan',
        'question_en' => 'Late return fines',
        'keywords' => [
            'denda', 'dendanya', 'terlambat', 'telat', 'keterlambatan', 'denda keterlambatan',
            'fine', 'fines', 'late', 'overdue', 'penalty',
        ],
        'answer' => 'Denda Rp1.000 per hari per eksemplar, dihitung sejak hari pertama keterlambatan. Denda dibayar di meja sirkulasi sebelum meminjam lagi.',
        'answer_en' => 'Rp1,000 per day per item, counted from the first day of delay. Fines are paid at the circulation desk before borrowing again.',
    ],
    [
        'id' => 'reserve',
        'question' => 'Memesan buku yang sedang dipinjam',
        'question_en' => 'Reserving a book that is on loan',
        'keywords' => [
            'pesan buku', 'reservasi', 'booking', 'antre', 'antrian', 'dipesan',
            'reserve', 'reservation', 'queue', 'hold', 'request book',
        ],
        'answer' => 'Ajukan reservasi di meja layanan atau melalui surel dengan menyebutkan judul dan nomor anggota. Anda dihubungi begitu buku dikembalikan, dengan masa pengambilan 3 hari.',
        'answer_en' => 'Ask at the service desk or by e-mail, quoting the title and your member number. We contact you when the item is returned; it is held for 3 days.',
    ],
    [
        'id' => 'facilities',
        'question' => 'Ruang baca dan wifi',
        'question_en' => 'Reading room and wifi',
        'keywords' => [
            'fasilitas', 'sarana', 'facilities', 'wifi', 'wi-fi', 'internet',
            'ruang baca', 'tempat baca', 'ruang diskusi', 'diskusi', 'ac', 'listrik',
            'reading room', 'study', 'power outlet', 'discussion room',
        ],
        'answer' => 'Ruang baca ber-AC tersedia di lantai 2 dengan 40 kursi dan colokan listrik. Wifi gratis: SSID "Perpustakaan", kata sandi di meja layanan.',
        'answer_en' => 'An air-conditioned reading room is on the second floor with 40 seats and power outlets. Free wifi: SSID "Perpustakaan", password at the service desk.',
    ],
    [
        'id' => 'reference',
        'question' => 'Layanan referensi dan bantuan penelitian',
        'question_en' => 'Reference and research help',
        'keywords' => [
            'referensi', 'bantuan penelitian', 'pustakawan', 'konsultasi', 'membantu mencari',
            'reference', 'research', 'librarian', 'consultation', 'help me find',
        ],
        'answer' => 'Pustakawan referensi siap membantu pencarian literatur, sitasi, dan akses basis data berlangganan setiap hari kerja pukul 09.00–15.00.',
        'answer_en' => 'A reference librarian helps with literature searches, citations and subscribed databases every weekday from 09.00 to 15.00.',
    ],
    [
        'id' => 'visit',
        'question' => 'Kunjungan sekolah atau rombongan',
        'question_en' => 'School or group visits',
        'keywords' => [
            'kunjungan', 'rombongan', 'sekolah', 'study tour', 'tur', 'kelompok',
            'visit', 'school', 'group', 'tour',
        ],
        'answer' => 'Kunjungan rombongan minimal 10 orang, diajukan paling lambat 7 hari sebelumnya melalui surel, dengan tanggal, jumlah peserta, dan jenjang pendidikan.',
        'answer_en' => 'Group visits for 10 people or more must be requested at least 7 days in advance by e-mail, with the date, number of participants and education level.',
    ],
    [
        'id' => 'donation',
        'question' => 'Donasi buku',
        'question_en' => 'Book donations',
        'keywords' => [
            'donasi', 'sumbang buku', 'hibah', 'menyumbang', 'hadiah buku',
            'donation', 'donate', 'give books', 'gift',
        ],
        'answer' => 'Kami menerima buku sumbangan dalam kondisi layak baca dan relevan. Hubungi meja layanan untuk pemeriksaan awal; buku yang diterima diberi cap donasi.',
        'answer_en' => 'We accept donated books in readable condition that fit the collection. Contact the service desk for an initial check; accepted books are stamped as donations.',
    ],
    [
        'id' => 'lost-card',
        'question' => 'Kartu anggota hilang',
        'question_en' => 'Lost membership card',
        'keywords' => [
            'kartu hilang', 'kartu anggota hilang', 'kehilangan kartu', 'kartu rusak',
            'kartu anggota rusak', 'ganti kartu', 'kartu anggota saya hilang',
            'kartu saya hilang', 'kehilangan kartu anggota', 'lost card',
            'lost membership card', 'replace card', 'card damaged',
        ],
        'answer' => 'Lapor ke meja layanan dengan membawa KTP. Kartu lama diblokir dan kartu pengganti dicetak dengan biaya Rp5.000.',
        'answer_en' => 'Report it at the service desk with your ID card. The old card is blocked and a replacement is printed for Rp5,000.',
    ],
];
