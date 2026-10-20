<?php

return [
    'accepted' => ':attribute harus disetujui.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Kata sandi tidak sesuai.',
    'digits' => ':attribute harus terdiri dari :digits angka.',
    'email' => ':attribute harus berupa alamat surel yang valid.',
    'exists' => ':attribute yang dipilih tidak valid.',
    'image' => ':attribute harus berupa gambar.',
    'in' => ':attribute yang dipilih tidak valid.',
    'integer' => ':attribute harus berupa bilangan bulat.',
    'max' => [
        'array' => ':attribute tidak boleh berisi lebih dari :max item.',
        'file' => 'Ukuran :attribute tidak boleh lebih dari :max kilobita.',
        'numeric' => ':attribute tidak boleh lebih dari :max.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
    ],
    'min' => [
        'array' => ':attribute harus berisi minimal :min item.',
        'file' => 'Ukuran :attribute minimal :min kilobita.',
        'numeric' => ':attribute minimal :min.',
        'string' => ':attribute harus terdiri dari minimal :min karakter.',
    ],
    'required' => ':attribute wajib diisi.',
    'string' => ':attribute harus berupa teks.',
    'unique' => ':attribute sudah digunakan.',
    'attributes' => [
        'identitas' => 'username, NUPTK, atau NISN',
        'password' => 'kata sandi',
        'two_factor_code' => 'kode autentikasi dua faktor',
        'nama' => 'nama',
        'nisn' => 'NISN',
        'nuptk' => 'NUPTK',
        'foto' => 'foto bukti',
    ],
];
