const { NlpManager } = require('node-nlp');

async function trainAndSaveModel() {
    // Inisialisasi NlpManager dengan konfigurasi Duckling yang eksplisit
    const manager = new NlpManager({
        languages: ['id'],
        forceNER: true,
        ner: {
            useDuckling: true,
            // Beritahu nlp-manager alamat server Duckling
            ducklingUrl:'http://localhost:8001',
            // --- PERBAIKAN PENTING ---
            // Secara eksplisit tambahkan parameter 'dims' ke setiap request ke Duckling
            // untuk memastikan dimensi 'time' selalu aktif.
          
        }
    });
    
    console.log('Mempersiapkan data latihan untuk model AI...');

    //=========================================================
    // DATA LATIHAN (CORPUS)
    //=========================================================
    manager.addDocument('id', 'halo', 'agent.greeting');
    manager.addDocument('id', 'hai', 'agent.greeting');
    manager.addDocument('id', 'selamat pagi', 'agent.greeting');
    manager.addDocument('id', 'pagi', 'agent.greeting');
    manager.addDocument('id', 'siang', 'agent.greeting');
    manager.addDocument('id', 'sore', 'agent.greeting');

    manager.addDocument('id', 'terima kasih', 'agent.thanks');
    manager.addDocument('id', 'makasih ya', 'agent.thanks');
    manager.addDocument('id', 'oke thanks', 'agent.thanks');
    manager.addDocument('id', 'sip makasih', 'agent.thanks');

    manager.addDocument('id', 'batal', 'agent.cancel');
    manager.addDocument('id', 'batalkan', 'agent.cancel');
    manager.addDocument('id', 'ga jadi', 'agent.cancel');
    
    manager.addDocument('id', 'jadwal saya', 'schedule.get');
    manager.addDocument('id', 'agenda saya apa saja', 'schedule.get');
    manager.addDocument('id', 'cek jadwal hari ini', 'schedule.get');
    manager.addDocument('id', 'liatin agenda minggu ini', 'schedule.get');
    manager.addDocument('id', 'jadwal untuk bulan ini', 'schedule.get');
    manager.addDocument('id', 'apa ada jadwal besok', 'schedule.get');
    manager.addDocument('id', 'jadwal', 'schedule.get');
    manager.addDocument('id', 'agenda', 'schedule.get');

    manager.addDocument('id', 'buat jadwal baru', 'schedule.create');
    manager.addDocument('id', 'tolong buatkan agenda', 'schedule.create');
    manager.addDocument('id', 'set jadwal', 'schedule.create');
    manager.addDocument('id', 'buatkan agenda untuk tanda tangan akta besok jam 10 pagi', 'schedule.create');
    manager.addDocument('id', 'jadwalkan meeting', 'schedule.create');
    manager.addDocument('id', 'bikin jadwal dong', 'schedule.create');

    //=========================================================
    // JAWABAN DEFAULT
    //=========================================================
    manager.addAnswer('id', 'agent.greeting', 'Halo! Ada yang bisa saya bantu? (Contoh: "jadwal hari ini", "buat agenda baru")');
    manager.addAnswer('id', 'agent.thanks', 'Sama-sama!');
    manager.addAnswer('id', 'agent.cancel', 'Baik, proses dibatalkan.');

    //=========================================================
    // PROSES TRAINING DAN SIMPAN MODEL
    //=========================================================
    console.log('Training model AI sedang berjalan (dengan Duckling eksternal)...');
    await manager.train();
    console.log('Training selesai.');

    manager.save('./model.nlp', true);
    console.log('Model AI berhasil disimpan ke file: model.nlp');
}

trainAndSaveModel();