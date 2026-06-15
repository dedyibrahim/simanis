const axios = require('axios');

// Alamat server Laravel Anda
const laravelUrl = 'http://127.0.0.1:8000';

async function checkConnection() {
    console.log(`Mencoba menghubungi server Laravel di ${laravelUrl}...`);
    try {
        // Kita coba akses route '/' yang paling dasar
        const response = await axios.get(laravelUrl, { timeout: 5000 }); // Timeout 5 detik
        console.log('KONEKSI BERHASIL!');
        console.log('Status:', response.status);
        console.log('Server Laravel merespons.');
    } catch (error) {
        console.error('KONEKSI GAGAL!');
        if (error.code === 'ECONNREFUSED') {
            console.error('Error: Connection Refused. Pastikan server Laravel (php artisan serve) sedang berjalan di port yang benar (8000).');
        } else if (error.code === 'EHOSTUNREACH') {
            console.error('Error: Host Unreachable. Periksa konfigurasi jaringan atau firewall.');
        } else {
            console.error('Terjadi error lain:', error.message);
        }
    }
}

checkConnection();