//! Cryptographic helpers for the MySQL protocol and member authentication.

pub(crate) fn sha1(data: &[u8]) -> [u8; 20] {
    let mut h: [u32; 5] = [0x67452301, 0xEFCDAB89, 0x98BADCFE, 0x10325476, 0xC3D2E1F0];
    let mut msg = data.to_vec();
    let bit_len = (data.len() as u64).wrapping_mul(8);
    msg.push(0x80);
    while msg.len() % 64 != 56 {
        msg.push(0);
    }
    msg.extend_from_slice(&bit_len.to_be_bytes());
    for block in msg.chunks(64) {
        let mut w = [0u32; 80];
        for (i, word) in block.chunks(4).enumerate() {
            w[i] = u32::from_be_bytes([word[0], word[1], word[2], word[3]]);
        }
        for i in 16..80 {
            w[i] = (w[i - 3] ^ w[i - 8] ^ w[i - 14] ^ w[i - 16]).rotate_left(1);
        }
        let (mut a, mut b, mut c, mut d, mut e) = (h[0], h[1], h[2], h[3], h[4]);
        for (i, &word) in w.iter().enumerate() {
            let (f, k) = match i {
                0..=19 => ((b & c) | ((!b) & d), 0x5A827999),
                20..=39 => (b ^ c ^ d, 0x6ED9EBA1),
                40..=59 => ((b & c) | (b & d) | (c & d), 0x8F1BBCDC),
                _ => (b ^ c ^ d, 0xCA62C1D6),
            };
            let temp = a
                .rotate_left(5)
                .wrapping_add(f)
                .wrapping_add(e)
                .wrapping_add(k)
                .wrapping_add(word);
            e = d;
            d = c;
            c = b.rotate_left(30);
            b = a;
            a = temp;
        }
        h[0] = h[0].wrapping_add(a);
        h[1] = h[1].wrapping_add(b);
        h[2] = h[2].wrapping_add(c);
        h[3] = h[3].wrapping_add(d);
        h[4] = h[4].wrapping_add(e);
    }
    let mut out = [0u8; 20];
    for (i, value) in h.iter().enumerate() {
        out[i * 4..i * 4 + 4].copy_from_slice(&value.to_be_bytes());
    }
    out
}

const SHA256_K: [u32; 64] = [
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
];

pub(crate) fn sha256(data: &[u8]) -> [u8; 32] {
    let mut h: [u32; 8] = [
        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab,
        0x5be0cd19,
    ];
    let mut msg = data.to_vec();
    let bit_len = (data.len() as u64).wrapping_mul(8);
    msg.push(0x80);
    while msg.len() % 64 != 56 {
        msg.push(0);
    }
    msg.extend_from_slice(&bit_len.to_be_bytes());
    for block in msg.chunks(64) {
        let mut w = [0u32; 64];
        for (i, word) in block.chunks(4).enumerate() {
            w[i] = u32::from_be_bytes([word[0], word[1], word[2], word[3]]);
        }
        for i in 16..64 {
            let s0 = w[i - 15].rotate_right(7) ^ w[i - 15].rotate_right(18) ^ (w[i - 15] >> 3);
            let s1 = w[i - 2].rotate_right(17) ^ w[i - 2].rotate_right(19) ^ (w[i - 2] >> 10);
            w[i] = w[i - 16]
                .wrapping_add(s0)
                .wrapping_add(w[i - 7])
                .wrapping_add(s1);
        }
        let (mut a, mut b, mut c, mut d, mut e, mut f, mut g, mut hh) =
            (h[0], h[1], h[2], h[3], h[4], h[5], h[6], h[7]);
        for i in 0..64 {
            let s1 = e.rotate_right(6) ^ e.rotate_right(11) ^ e.rotate_right(25);
            let ch = (e & f) ^ ((!e) & g);
            let temp1 = hh
                .wrapping_add(s1)
                .wrapping_add(ch)
                .wrapping_add(SHA256_K[i])
                .wrapping_add(w[i]);
            let s0 = a.rotate_right(2) ^ a.rotate_right(13) ^ a.rotate_right(22);
            let maj = (a & b) ^ (a & c) ^ (b & c);
            let temp2 = s0.wrapping_add(maj);
            hh = g;
            g = f;
            f = e;
            e = d.wrapping_add(temp1);
            d = c;
            c = b;
            b = a;
            a = temp1.wrapping_add(temp2);
        }
        h[0] = h[0].wrapping_add(a);
        h[1] = h[1].wrapping_add(b);
        h[2] = h[2].wrapping_add(c);
        h[3] = h[3].wrapping_add(d);
        h[4] = h[4].wrapping_add(e);
        h[5] = h[5].wrapping_add(f);
        h[6] = h[6].wrapping_add(g);
        h[7] = h[7].wrapping_add(hh);
    }
    let mut out = [0u8; 32];
    for (i, value) in h.iter().enumerate() {
        out[i * 4..i * 4 + 4].copy_from_slice(&value.to_be_bytes());
    }
    out
}


/// MD5 exists solely to verify and immediately upgrade legacy SLiMS hashes.
/// Never use MD5 for new passwords, signatures, or security tokens.
#[cfg(feature = "member-crypt")]
pub(crate) fn legacy_md5(data: &[u8]) -> [u8; 16] {
    const S: [u32; 64] = [7,12,17,22,7,12,17,22,7,12,17,22,7,12,17,22,5,9,14,20,5,9,14,20,5,9,14,20,5,9,14,20,4,11,16,23,4,11,16,23,4,11,16,23,4,11,16,23,6,10,15,21,6,10,15,21,6,10,15,21,6,10,15,21];
    const K: [u32; 64] = [0xd76aa478,0xe8c7b756,0x242070db,0xc1bdceee,0xf57c0faf,0x4787c62a,0xa8304613,0xfd469501,0x698098d8,0x8b44f7af,0xffff5bb1,0x895cd7be,0x6b901122,0xfd987193,0xa679438e,0x49b40821,0xf61e2562,0xc040b340,0x265e5a51,0xe9b6c7aa,0xd62f105d,0x02441453,0xd8a1e681,0xe7d3fbc8,0x21e1cde6,0xc33707d6,0xf4d50d87,0x455a14ed,0xa9e3e905,0xfcefa3f8,0x676f02d9,0x8d2a4c8a,0xfffa3942,0x8771f681,0x6d9d6122,0xfde5380c,0xa4beea44,0x4bdecfa9,0xf6bb4b60,0xbebfbc70,0x289b7ec6,0xeaa127fa,0xd4ef3085,0x04881d05,0xd9d4d039,0xe6db99e5,0x1fa27cf8,0xc4ac5665,0xf4292244,0x432aff97,0xab9423a7,0xfc93a039,0x655b59c3,0x8f0ccc92,0xffeff47d,0x85845dd1,0x6fa87e4f,0xfe2ce6e0,0xa3014314,0x4e0811a1,0xf7537e82,0xbd3af235,0x2ad7d2bb,0xeb86d391];
    let mut msg = data.to_vec();
    msg.push(0x80);
    while msg.len() % 64 != 56 { msg.push(0); }
    msg.extend_from_slice(&(data.len() as u64).wrapping_mul(8).to_le_bytes());
    let mut state: [u32; 4] = [0x67452301, 0xefcdab89, 0x98badcfe, 0x10325476];
    for block in msg.chunks_exact(64) {
        let mut words = [0u32; 16];
        for (i, w) in block.chunks_exact(4).enumerate() { words[i] = u32::from_le_bytes(w.try_into().unwrap()); }
        let [mut a, mut b, mut c, mut d] = state;
        for i in 0..64 {
            let (f, g) = if i < 16 { ((b & c) | ((!b) & d), i) }
                else if i < 32 { ((d & b) | ((!d) & c), (5*i+1)%16) }
                else if i < 48 { (b ^ c ^ d, (3*i+5)%16) }
                else { (c ^ (b | !d), (7*i)%16) };
            let next = b.wrapping_add(a.wrapping_add(f).wrapping_add(K[i]).wrapping_add(words[g]).rotate_left(S[i]));
            a=d; d=c; c=b; b=next;
        }
        for (v, n) in state.iter_mut().zip([a,b,c,d]) { *v = v.wrapping_add(n); }
    }
    let mut out = [0u8; 16];
    for (i, v) in state.iter().enumerate() { out[i*4..i*4+4].copy_from_slice(&v.to_le_bytes()); }
    out
}

/// Verify PHP bcrypt password hashes with the system's libcrypt implementation.
/// This is opt-in; the experimental member login uses it when enabled.
///
/// Only Linux libcrypt implementations supporting bcrypt ($2a$, $2b$, $2y$)
/// are supported. Refuse excessive cost to bound attacker-controlled work.
#[cfg(all(feature = "member-crypt", target_os = "linux"))]
pub mod member_password {
    use std::ffi::{CStr, CString};
    use std::os::raw::{c_char, c_int, c_ulong, c_void};
    use std::io::Read;

    #[link(name = "crypt")]
    extern "C" {
        fn crypt_ra(key: *const c_char, salt: *const c_char, data: *mut *mut c_void, size: *mut c_int) -> *mut c_char;
        fn crypt_gensalt_rn(prefix: *const c_char, count: c_ulong, input: *const c_char, input_size: c_int, output: *mut c_char, output_size: c_int) -> *mut c_char;
    }
    extern "C" { fn free(ptr: *mut c_void); }

    /// Generate an interoperable bcrypt hash using fresh OS randomness.
    /// Reject >72 bytes rather than silently truncating a new password.
    pub fn hash(password: &str) -> std::io::Result<String> {
        let error = || std::io::Error::new(std::io::ErrorKind::Other, "password hashing failed");
        if password.is_empty() || password.len() > 72 || password.as_bytes().contains(&0) { return Err(error()); }
        let key = CString::new(password).map_err(|_| error())?;
        let prefix = CString::new("$2b$").unwrap();
        let mut entropy = [0u8; 16];
        std::fs::File::open("/dev/urandom")?.read_exact(&mut entropy)?;
        let mut salt = [0 as c_char; 128];
        let salt_ptr = unsafe { crypt_gensalt_rn(prefix.as_ptr(), 10, entropy.as_ptr().cast(), entropy.len() as c_int, salt.as_mut_ptr(), salt.len() as c_int) };
        if salt_ptr.is_null() { return Err(error()); }
        let result = unsafe {
            let mut data: *mut c_void = std::ptr::null_mut();
            let mut size = 0;
            let ptr = crypt_ra(key.as_ptr(), salt.as_ptr(), &mut data, &mut size);
            let out = if ptr.is_null() { None } else { CStr::from_ptr(ptr).to_str().ok().map(str::to_owned) };
            free(data);
            out
        }.ok_or_else(error)?;
        if result.len() != 60 || !result.starts_with("$2b$10$") { return Err(error()); }
        Ok(result)
    }

    pub fn verify(password: &str, hash: &str) -> bool {
        let h = hash.as_bytes();
        if h.len() != 60 || h[0] != b'$' || h[1] != b'2'
            || !matches!(h[2], b'a' | b'b' | b'y') || h[3] != b'$'
            || !h[4].is_ascii_digit() || !h[5].is_ascii_digit() || h[6] != b'$'
            || !h[7..].iter().all(|b| b.is_ascii_alphanumeric() || *b == b'.' || *b == b'/')
        {
            return false;
        }
        let cost = (h[4] - b'0') * 10 + (h[5] - b'0');
        if !(4..=16).contains(&cost) { return false; }
        // bcrypt (and PHP's bcrypt password_verify) consumes at most 72
        // bytes, not 72 Unicode characters. Reject interior NUL explicitly.
        if password.as_bytes().contains(&0) { return false; }
        let key_bytes = &password.as_bytes()[..password.len().min(72)];
        let (Ok(key), Ok(salt)) = (CString::new(key_bytes), CString::new(hash)) else {
            return false;
        };
        // SAFETY: arguments are NUL-terminated; crypt_ra allocates its own
        // thread-local working area, which is copied and freed before return.
        let result = unsafe {
            let mut data: *mut c_void = std::ptr::null_mut();
            let mut size = 0;
            let result = crypt_ra(key.as_ptr(), salt.as_ptr(), &mut data, &mut size);
            let output = if result.is_null() { None } else { Some(CStr::from_ptr(result).to_bytes().to_vec()) };
            free(data);
            output
        };
        let Some(result) = result else { return false };
        if result.len() != h.len() { return false; }
        result.iter().zip(h).fold(0u8, |acc, (a, b)| acc | (a ^ b)) == 0
    }

    #[cfg(test)]
    mod tests {
        use super::*;
        #[test]
        fn php_bcrypt_and_malformed_hashes() {
            let y = "$2y$04$qGzOSwDpk3DwkERJbP.zsuFsyyjmAMwbQByLMBLb/Y77gxpIIJWGO";
            assert!(verify("siti rahma", y));
            assert!(!verify("wrong", y));
            assert!(verify("secret", "$2b$04$kfEuXqkxREGoRdFNFMfJFO7ARi6uiBzBLQJ7DMGAWdAPhdoPq9V2."));
            assert!(verify("U*U", "$2a$05$ReoRRNafjszTXFDKC.w8GOIUgg57DldYOpOhuff7sL2N/sq3zV/XC"));
            assert!(!verify("x\0y", y));
            assert!(!verify("password", "$2y$99$IyxvT/xs1wt2fvMl6h1JZuQuvN4xAlA7no9h31tp2OAfiIvI.ERDu"));
            assert!(!verify("password", "$2x$10$IyxvT/xs1wt2fvMl6h1JZuQuvN4xAlA7no9h31tp2OAfiIvI.ERDu"));
            assert!(!verify("password", ""));
            let long_hash = "$2b$04$8T4036RUtSFJ9KKgJGwkruYskiF0rbYUuumtKQDhOZa97C7fz2jcW";
            assert!(verify(&"a".repeat(72), long_hash));
            assert!(verify(&"a".repeat(600), long_hash));
            assert!(!verify(&format!("{}b", "a".repeat(71)), long_hash));
        }
        #[test]
        fn legacy_md5_vectors() {
            let hex = |bytes: &[u8]| super::super::legacy_md5(bytes).iter().map(|b| format!("{b:02x}")).collect::<String>();
            assert_eq!(hex(b""), "d41d8cd98f00b204e9800998ecf8427e");
            assert_eq!(hex(b"abc"), "900150983cd24fb0d6963f7d28e17f72");
            assert_eq!(hex(b"The quick brown fox jumps over the lazy dog"), "9e107d9d372bb6826bd81d3542a419d6");
            assert_eq!(hex(&[b'a'; 1000]), "cabe45dcc9ae5b66ba86600cca6b8ba8");
        }

        #[test]
        fn fresh_bcrypt_hashes_round_trip_and_reject_bad_inputs() {
            let first = hash("FreshPass1!").unwrap();
            let second = hash("FreshPass1!").unwrap();
            assert_ne!(first, second);
            assert!(verify("FreshPass1!", &first));
            assert!(!verify("WrongPass1!", &first));
            assert!(hash(&"a".repeat(73)).is_err());
            assert!(hash("bad\0password").is_err());
        }

        #[test]
        fn concurrent_verification() {
            let threads: Vec<_> = (0..8).map(|_| std::thread::spawn(|| {
                for _ in 0..4 {
                    assert!(verify("secret", "$2b$04$kfEuXqkxREGoRdFNFMfJFO7ARi6uiBzBLQJ7DMGAWdAPhdoPq9V2."));
                }
            })).collect();
            for thread in threads { thread.join().unwrap(); }
        }
    }
}

#[cfg(all(feature = "member-crypt", not(target_os = "linux")))]
compile_error!("member-crypt currently requires Linux libcrypt");
