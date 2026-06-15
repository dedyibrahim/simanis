<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $user = [
                ['id_user' => '0001', 'username' => 'admin',    'nama_lengkap' => 'Admin',                     'email' => 'dedy@notaris-jakarta.com',              'phone' => '0887487772',    'level_user' => 'Super Admin',   'password' => Hash::make('admin@123'),  'foto' => '5ebd6be3c03d1.png',      'status' => true],
                ['id_user' => '0002', 'username' => 'wisnu',    'nama_lengkap' => 'Wisnu Subroto N.A',         'email' => 'yuniaryanto679@gmail.com',              'phone' => '087877912311',  'level_user' => 'User',          'password' => Hash::make('wisnu@123'),  'foto' => '5df0a79c94e92.png',      'status' => true],
                ['id_user' => '0003', 'username' => 'dian',     'nama_lengkap' => 'Siti Rizki Dianti',         'email' => 'dian@notaris-jakarta.com',              'phone' => '085289885222',  'level_user' => 'User',          'password' => Hash::make('dian@123'),   'foto' => null,                     'status' => true],
                ['id_user' => '0004', 'username' => 'prima',    'nama_lengkap' => 'Prima Yuddy F Y',           'email' => 'prima@notaris-jakarta.com',             'phone' => '085263908704',  'level_user' => 'User',          'password' => Hash::make('prima@123'),  'foto' => null,                     'status' => true],
                ['id_user' => '0005', 'username' => 'dini',     'nama_lengkap' => 'Pratiwi S Dini',            'email' => 'dini@notaris-jakarta.com',              'phone' => '081273602067',  'level_user' => 'User',          'password' => Hash::make('dini@123'),   'foto' => null,                     'status' => true],
                ['id_user' => '0006', 'username' => 'rifka',    'nama_lengkap' => 'Rifka Ramadani',            'email' => 'rifka@notaris-jakarta.com',             'phone' => '087739397228',  'level_user' => 'User',          'password' => Hash::make('rifka@123'),  'foto' => null,                     'status' => true],
                ['id_user' => '0007', 'username' => 'yus',      'nama_lengkap' => 'Yus Suwandari',             'email' => 'yus@notaris-jakarta.com',               'phone' => '081280716583',  'level_user' => 'User',          'password' => Hash::make('yyus@123'),    'foto' => '5e6f20017aca7.png',      'status' => true],
                ['id_user' => '0008', 'username' => 'esthi',    'nama_lengkap' => 'Esthi Herlina',             'email' => 'esthi@notaris-jakarta.com',             'phone' => '081517697047',  'level_user' => 'User',          'password' => Hash::make('esthi@123'),  'foto' => '5d005f8da4b9d.png',      'status' => true],
                ['id_user' => '0010', 'username' => 'indy',     'nama_lengkap' => 'indarty',                   'email' => 'indy@notaris-jakarta.com',              'phone' => '087876227696',  'level_user' => 'User',          'password' => Hash::make('indy@123'),   'foto' => null,                     'status' => true],
                ['id_user' => '0011', 'username' => 'fitri',    'nama_lengkap' => 'Fitri Senjayani',           'email' => 'fitri@notaris-jakarta.com',             'phone' => '08121923365',   'level_user' => 'User',          'password' => Hash::make('fitri@123'),  'foto' => null,                     'status' => true],
                ['id_user' => '0012', 'username' => 'fadzri',   'nama_lengkap' => 'MK Fadzri Patriajaya',      'email' => 'fadzri@notaris-jakarta.com',            'phone' => '087788105424',  'level_user' => 'User',          'password' => Hash::make('fadzri@123'), 'foto' => '5df2eead14666.png',      'status' => true],
                ['id_user' => '0013', 'username' => 'rohmad',   'nama_lengkap' => 'agus rohmad',               'email' => 'agusrohmad300@gmail.com',               'phone' => '081806446192',  'level_user' => 'User',          'password' => Hash::make('rohmad@123'), 'foto' => '5f336f8ddb31b.png',      'status' => true],
                ['id_user' => '0014', 'username' => 'admin2',   'nama_lengkap' => 'Dewantari Handayani SH.MPA', 'email' => 'dewantari@notaris-jakarta.com',         'phone' => '-',             'level_user' => 'Admin',         'password' => Hash::make('admin2@123'), 'foto' => null,                     'status' => true],
                ['id_user' => '0016', 'username' => 'imam',     'nama_lengkap' => 'Imam Syafii',               'email' => 'imamsyafii060179@gmail.com',            'phone' => '087878914988',  'level_user' => 'User',          'password' => Hash::make('imam@123'),   'foto' => null,                     'status' => true],
                ['id_user' => '0017', 'username' => 'Sastra',   'nama_lengkap' => 'Sastra Wardana',            'email' => 'sastrawardana@notaris-jakarta.com',     'phone' => '081292235391',  'level_user' => 'User',          'password' => Hash::make('sastra@123'), 'foto' => null,                     'status' => true],
                ['id_user' => '0018', 'username' => 'eka',      'nama_lengkap' => 'Eka Andriani',              'email' => 'eka@notaris-jakarta.com',               'phone' => '0',             'level_user' => 'User',          'password' => Hash::make('eeka@123'),    'foto' => null,                     'status' => true],
                ['id_user' => '0019', 'username' => 'arsip',    'nama_lengkap' => 'Anak Magang',               'email' => 'arsip@gmail.com',                       'phone' => '081289903664',  'level_user' => 'User',          'password' => Hash::make('arsip@123'),  'foto' => null,                     'status' => true],
                ['id_user' => '0039', 'username' => 'amel',     'nama_lengkap' => 'amel',                      'email' => 'amel@gmail.com',                        'phone' => '-',             'level_user' => 'User',          'password' => Hash::make('amel@123'),   'foto' => null,                     'status' => true],
              ];

        DB::table('users')->insert($user);

        $this->call([
            DaftarAktas::class,
            DaftarClient::class,
            DaftarDokumen::class,
            DaftarBerkas::class,
        ]);
    }
}
