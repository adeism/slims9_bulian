<?php
/**
 * Evaluation corpus: what the chat must understand
 *
 * Each row is `[question, role, expectation, category]`:
 *
 *   'faq:hours'          must be answered from that FAQ entry
 *   'intent:biblio.list' must be classified as that intent, and must NOT be
 *                        answered by the FAQ
 *   'no-faq'             may be anything, but the FAQ must stay out of it
 *
 * The negative rows are the important ones: a catalogue search, a member
 * question or a circulation command must never be answered with opening
 * hours. `php index.php chatbot:eval` measures the whole file; the self-test
 * fails when accuracy drops or a negative row starts matching.
 *
 * @license GPLv3
 */

return [
    // ---- FAQ: opening hours -----------------------------------------
    ['jam buka', 'guest', 'faq:hours', 'faq'],
    ['buka jam berapa', 'guest', 'faq:hours', 'faq'],
    ['jam bukaa', 'guest', 'faq:hours', 'typo'],
    ['kapan perpustakaan tutup', 'guest', 'faq:hours', 'faq'],
    ['sabtu buka', 'member', 'faq:hours', 'faq'],
    ['opening hours', 'guest', 'faq:hours', 'faq-en'],
    ['jam layanan', 'staff', 'faq:hours', 'faq'],

    // ---- FAQ: address, phone, e-mail --------------------------------
    ['alamat perpustakaan', 'guest', 'faq:address', 'faq'],
    ['alamatnya dimana', 'guest', 'faq:address', 'faq'],
    ['lokasi perpustakaan', 'member', 'faq:address', 'faq'],
    ['alamt', 'guest', 'faq:address', 'typo'],
    ['where is the library', 'guest', 'faq:address', 'faq-en'],
    ['nomor telepon', 'guest', 'faq:phone', 'faq'],
    ['telepon perpustakaan', 'member', 'faq:phone', 'faq'],
    ['telepn', 'guest', 'faq:phone', 'typo'],
    ['whatsapp perpustakaan', 'guest', 'faq:phone', 'faq'],
    ['phone number', 'staff', 'faq:phone', 'faq-en'],
    ['email perpustakaan', 'guest', 'faq:email', 'faq'],
    ['surel', 'member', 'faq:email', 'faq'],
    ['e-mail address', 'guest', 'faq:email', 'faq-en'],

    // ---- FAQ: membership --------------------------------------------
    ['cara jadi anggota', 'guest', 'faq:membership', 'faq'],
    ['daftar anggota', 'guest', 'faq:membership', 'faq'],
    ['syarat jadi anggota', 'guest', 'faq:membership', 'faq'],
    ['bagaimana cara mendaftar', 'guest', 'faq:membership', 'faq'],
    ['how to become a member', 'guest', 'faq:membership', 'faq-en'],
    ['berapa biaya keanggotaan', 'guest', 'faq:membership-fee', 'faq'],
    ['biaya pendaftaran', 'guest', 'faq:membership-fee', 'faq'],
    ['membership fee', 'guest', 'faq:membership-fee', 'faq-en'],

    // ---- FAQ: loans, renew, fines -----------------------------------
    ['berapa lama boleh pinjam', 'guest', 'faq:loan-limit', 'faq'],
    ['batas pinjam buku', 'member', 'faq:loan-limit', 'faq'],
    ['maksimal pinjam berapa buku', 'member', 'faq:loan-limit', 'faq'],
    ['loan limit', 'guest', 'faq:loan-limit', 'faq-en'],
    ['perpanjang pinjaman', 'guest', 'faq:renew', 'faq'],
    ['cara memperpanjang', 'member', 'faq:renew', 'faq'],
    ['denda keterlambatan', 'guest', 'faq:fines', 'faq'],
    ['berapa dendanya', 'member', 'faq:fines', 'faq'],
    ['denda per hari', 'guest', 'faq:fines', 'faq'],
    ['fines', 'guest', 'faq:fines', 'faq-en'],

    // ---- FAQ: the rest of the sample --------------------------------
    ['cara reservasi buku', 'guest', 'faq:reserve', 'faq'],
    ['pesan buku', 'member', 'faq:reserve', 'faq'],
    ['ruang baca', 'guest', 'faq:facilities', 'faq'],
    ['wifi', 'guest', 'faq:facilities', 'faq'],
    ['password wifi', 'member', 'faq:facilities', 'faq'],
    ['layanan referensi', 'guest', 'faq:reference', 'faq'],
    ['bantuan referensi', 'member', 'faq:reference', 'faq'],
    ['kunjungan sekolah', 'staff', 'faq:visit', 'faq'],
    ['donasi buku', 'guest', 'faq:donation', 'faq'],
    ['menyumbang buku', 'member', 'faq:donation', 'faq'],
    ['kartu anggota hilang', 'member', 'faq:lost-card', 'faq'],
    ['kartu hilang', 'guest', 'faq:lost-card', 'faq'],
    ['reading room', 'guest', 'faq:facilities', 'faq-en'],
    ['donate books', 'guest', 'faq:donation', 'faq-en'],
    ['lost card', 'member', 'faq:lost-card', 'faq-en'],

    // ---- Catalogue search: never answered by the FAQ ----------------
    ['cari buku sejarah', 'guest', 'intent:biblio.list', 'search'],
    ['cari buku laskar pelangi', 'guest', 'intent:biblio.list', 'search'],
    ['cari sejarh', 'guest', 'intent:biblio.list', 'typo'],
    ['buku populer', 'guest', 'intent:biblio.list', 'search'],
    ['buku terbaru', 'member', 'intent:biblio.list', 'search'],
    ['find book history', 'guest', 'intent:biblio.list', 'search-en'],
    ['apakah buku #12 tersedia', 'guest', 'intent:biblio.get', 'catalogue'],
    ['status buku 12', 'member', 'intent:biblio.get', 'catalogue'],
    ['cek item B00001', 'staff', 'intent:item.get', 'catalogue'],
    ['sejarah indonesia', 'guest', 'no-faq', 'search'],

    // ---- Member: own data only --------------------------------------
    ['pinjaman saya', 'member', 'intent:my.loans', 'member'],
    ['pinjaman aktif saya', 'member', 'intent:my.loans', 'member'],
    ['buku apa yang saya pinjam', 'member', 'intent:my.loans', 'member'],
    ['denda saya', 'member', 'intent:my.profile', 'member'],
    ['berapa denda saya', 'member', 'intent:my.profile', 'member'],
    ['perpanjang pinjaman saya', 'member', 'intent:my.extend', 'member'],
    ['profil saya', 'member', 'intent:my.profile', 'member'],
    ['pinjaman saya jam buka', 'member', 'intent:my.loans', 'member'],

    // ---- Staff: circulation and reports -----------------------------
    ['pinjaman terlambat', 'staff', 'intent:loan.list', 'staff'],
    ['daftar pinjaman', 'staff', 'intent:loan.list', 'staff'],
    ['ringkasan hari ini', 'staff', 'intent:report.summary', 'staff'],
    ['laporan hari ini', 'staff', 'intent:report.summary', 'staff'],
    ['anggota M001', 'staff', 'intent:member.get', 'staff'],
    ['data anggota M001', 'staff', 'intent:member.get', 'staff'],
    ['pinjamkan B00001 ke anggota 123', 'staff', 'intent:loan.checkout', 'write'],
    ['kembalikan B00001', 'staff', 'intent:loan.checkin', 'write'],
    ['perpanjang pinjaman L0001', 'staff', 'intent:loan.extend', 'write'],
    ['pinjamkan B00001 ke anggota 123', 'guest', 'no-faq', 'policy'],
    ['kembalikan B00001', 'member', 'no-faq', 'policy'],

    // ---- Small talk and help ----------------------------------------
    ['halo', 'guest', 'intent:greeting', 'smalltalk'],
    ['selamat pagi', 'member', 'intent:greeting', 'smalltalk'],
    ['terima kasih', 'guest', 'intent:thanks', 'smalltalk'],
    ['bantuan', 'guest', 'intent:help', 'smalltalk'],
    ['menu', 'staff', 'intent:help', 'smalltalk'],
    ['siapa saya', 'member', 'intent:whoami', 'smalltalk'],
    ['doctor', 'staff', 'intent:doctor', 'smalltalk'],

    // ---- Must stay out of the FAQ ----------------------------------
    ['xyz abc', 'guest', 'no-faq', 'negative'],
    ['apa kabar', 'guest', 'no-faq', 'negative'],
    ['pinjamkan B00001 ke anggota 123', 'member', 'no-faq', 'negative'],
    ['berapa denda saya hari ini', 'member', 'no-faq', 'negative'],
    ['jam buka bank terdekat', 'guest', 'faq:hours', 'faq'],
    ['kapan buku saya jatuh tempo', 'member', 'no-faq', 'negative'],
    ['apakah buku sejarah tersedia', 'guest', 'no-faq', 'negative'],
    ['saya mau pinjam buku', 'member', 'no-faq', 'negative'],
    ['berapa lama buku saya dipinjam', 'member', 'faq:loan-limit', 'faq'],
    ['denda buku saya berapa', 'member', 'no-faq', 'negative'],

    // ---- English negatives -----------------------------------------
    ['search book history', 'guest', 'intent:biblio.list', 'search-en'],
    ['my loans', 'member', 'intent:my.loans', 'member-en'],
    ['overdue loans', 'staff', 'intent:loan.list', 'staff-en'],
    ['check out B00001 to member 123', 'staff', 'intent:loan.checkout', 'write-en'],
    ['hello', 'guest', 'intent:greeting', 'smalltalk-en'],
    ['help', 'guest', 'intent:help', 'smalltalk-en'],
    ['what can you do', 'guest', 'intent:help', 'smalltalk-en'],
    ['who am i', 'member', 'intent:whoami', 'smalltalk-en'],
    ['book #12', 'guest', 'intent:biblio.get', 'catalogue-en'],
    ['item B00001', 'staff', 'intent:item.get', 'catalogue-en'],

    // ---- Additions from the second review pass ---------------------
    ['di mana perpustakaan', 'guest', 'faq:address', 'faq'],
    ['nomor telepon perpustakaan', 'guest', 'faq:phone', 'faq'],
    ['berapa iuran anggota', 'guest', 'faq:membership-fee', 'faq'],
    ['cara memperpanjang pinjaman', 'member', 'faq:renew', 'faq'],
    ['bisa reservasi buku', 'member', 'faq:reserve', 'faq'],
    ['fasilitas apa saja di perpustakaan', 'guest', 'faq:facilities', 'faq'],
    ['bagaimana cara menyumbang buku', 'guest', 'faq:donation', 'faq'],
    ['surel pustakawan', 'guest', 'faq:email', 'faq'],
    ['kartu anggota saya hilang', 'member', 'faq:lost-card', 'faq'],
    ['daftar pinjaman saya', 'member', 'intent:my.loans', 'member'],
    ['ringkasan koleksi', 'staff', 'intent:report.summary', 'staff'],
    ['jadwal kereta malam', 'guest', 'no-faq', 'negative'],
    ['berapa harga tiket bus', 'guest', 'no-faq', 'negative'],
    ['biaya kirim paket', 'guest', 'no-faq', 'negative'],
    ['cuaca hari ini bagaimana', 'guest', 'no-faq', 'negative'],
];
