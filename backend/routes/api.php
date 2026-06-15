<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\DatabaseBackupController;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\AdminReportoriumController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DokumenNotaris;
use App\Http\Controllers\DocumentAccessController;
use App\Http\Controllers\EmployeeChatController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\JadwalNotarisController;
use App\Http\Controllers\LayananController;
use App\Http\Controllers\Login;
use App\Http\Controllers\Order;
use App\Http\Controllers\PembuatanNomor;
use App\Http\Controllers\Pencarian;
use App\Http\Controllers\Report;
use App\Http\Controllers\ReportSettingController;
use App\Http\Controllers\TandaTerimaController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PeminjamanMinutaController;
use App\Http\Controllers\BantekController; // Controller Baru

use App\Models\BukuNotaris;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/auth/user', function (Request $request) {
    return $request->user();
});

Route::prefix('auth')->group(function () {
    // Email/Password Login
    Route::post('/login', [Login::class, 'SignIn']);

    // WhatsApp Login Endpoints
    Route::prefix('/whatsapp')->group(function () {
        Route::post('/verify', [Login::class, 'SignInWhatsapp']); // Verify OTP
        Route::post('/request-otp', [Login::class, 'RequestWhatsappOtp']); // Request OTP
        Route::get('/check/{phone}', [Login::class, 'CheckWhatsappNumber']); // Check number registration
    });

    // Google Login
    Route::post('/google', [Login::class, 'SignInGoogle']);

    // Logout
    Route::post('/logout', [Login::class, 'SignOut'])->middleware('auth:sanctum');
});

//Route::post('/SignIn', [Login::class, 'SignIn']);
//Route::post('/SignInGoogle', [Login::class, 'SignInGoogle']);

//Route::post('/GetEventsCalenderGoogle', [GoogleCalendarController::class, 'getEvents']);
//Route::post('/SetEventsCalenderGoogle', [GoogleCalendarController::class, 'setEvents']);
//Route::post('/DeleteEventsCalenderGoogle', [GoogleCalendarController::class, 'deleteEvents']);


Route::group(['middleware' => ['auth:sanctum']], function () {

    // Grup untuk semua yang berhubungan dengan Event
    Route::prefix('events')->group(function () {
        Route::get('/', [EventController::class, 'index']);
        Route::post('/', [EventController::class, 'store']);
        Route::post('/send-reminder-bulk', [EventController::class, 'sendBulkReminder']);
        Route::post('/{id}/send-reminder', [EventController::class, 'sendReminder']);
        Route::get('/{id}', [EventController::class, 'show']);
        Route::put('/{id}', [EventController::class, 'update']);
        Route::patch('/{id}', [EventController::class, 'update']);
        Route::delete('/{id}', [EventController::class, 'destroy']);
    });

    Route::get('/data-user', [UserController::class, 'DataUser']);

});

Route::get('/get-user-schedule', [EventController::class, 'getScheduleForChatbot']);
Route::get('/chatbot-assistants', [EventController::class, 'getAssistantsForChatbot']);
Route::post('/create-event-from-chat', [EventController::class, 'createEventFromChatbot']);
Route::post('/delete-event-from-chat', [EventController::class, 'deleteEventFromChatbot']);
Route::get('/chatbot-search-client', [ClientController::class, 'searchForChatbot']);
Route::post('/document-access/decision-from-chat', [DocumentAccessController::class, 'decideFromChatbot']);
Route::get('/chatbot-reportorium-monthly', [AdminReportoriumController::class, 'monthlyReportForChatbot']);


//Route::get('/data-user', [UserController::class, 'DataUser']);

Route::get('/CetakLaporanNotaris', [Report::class, 'CetakLaporanNotaris']);
Route::get('/CetakLaporanLegalisasi', [Report::class, 'CetakLaporanLegalisasi']);
Route::get('/CetakLaporanWarmerking', [Report::class, 'CetakLaporanWarmerking']);
Route::get('/CetakLaporanPPAT', [Report::class, 'CetakLaporanPPAT']);
Route::get('/CetakInvoice', [Report::class, 'CetakInvoice']);
Route::get('/CetakLabelBantek', [Report::class, 'CetakLabelBantek']);
Route::get('/CetakTandaTerima', [Report::class, 'CetakTandaTerima']);

Route::group(['prefix' => 'auth/user', 'middleware' => 'auth:sanctum'], function () {
    Route::post('/SaveAccount', [UserController::class, 'SaveAccount']);
    Route::post('/DeleteAccount', [UserController::class, 'DeleteAccount']);
    Route::get('/DataUser', [UserController::class, 'DataUser']);
    Route::post('/UpdatePassword', [UserController::class, 'UpdatePassword']);
    Route::post('/UploadFoto', [UserController::class, 'UploadFoto']);
});

Route::group(['prefix' => 'auth', 'middleware' => 'auth:sanctum'], function () {
    Route::get('/getDashboard', [DashboardController::class, 'getDashboard']);
    Route::get('/report-settings', [ReportSettingController::class, 'show']);
    Route::put('/report-settings', [ReportSettingController::class, 'update']);
    Route::post('/report-settings/logo', [ReportSettingController::class, 'uploadLogo']);
    Route::get('/database-backups', [DatabaseBackupController::class, 'index']);
    Route::post('/database-backups', [DatabaseBackupController::class, 'store']);
    Route::delete('/database-backups/old', [DatabaseBackupController::class, 'prune']);
    Route::get('/database-backups/download/{fileName}', [DatabaseBackupController::class, 'download'])->where('fileName', '.*');

    Route::post('/SignOut', [Login::class, 'SignOut']);
    Route::post('/getDaftarAkta', [PembuatanNomor::class, 'getDaftarAkta']);
    Route::post('/getDaftarClient', [PembuatanNomor::class, 'getDaftarClient']);
    Route::get('/getDaftarAsisten', [DashboardController::class, 'getDaftarAsisten']);

    Route::post('/SimpanNomorNotaris', [PembuatanNomor::class, 'SimpanNomorNotaris']);
    Route::post('/DeleteNomorNotaris', [PembuatanNomor::class, 'DeleteNomorNotaris']);
    Route::post('/SimpanNomorNotarisLama', [PembuatanNomor::class, 'SimpanNomorNotarisLama']);
    Route::post('/SimpanNomorPPAT', [PembuatanNomor::class, 'SimpanNomorPPAT']);
    Route::post('/DeleteNomorPPAT', [PembuatanNomor::class, 'DeleteNomorPPAT']);
    Route::post('/SimpanNomorLegalisasi', [PembuatanNomor::class, 'SimpanNomorLegalisasi']);
    Route::post('/SimpanNomorWarmerking', [PembuatanNomor::class, 'SimpanNomorWarmerking']);
    Route::post('/SimpanNomorSuratNotaris', [PembuatanNomor::class, 'SimpanNomorSuratNotaris']);
    Route::post('/SimpanNomorSuratPPAT', [PembuatanNomor::class, 'SimpanNomorSuratPPAT']);
    Route::post('/SimpanPesanan', [PembuatanNomor::class, 'SimpanPesanan']);

    Route::post('/SimpanNomorInvoiceTax', [PembuatanNomor::class, 'SimpanNomorInvoiceTax']);
    Route::post('/SimpanJadwalNotaris', [PembuatanNomor::class, 'SimpanJadwalNotaris']);

    Route::post('/getBukuNotaris', [PembuatanNomor::class, 'getBukuNotaris']);
    Route::get('/getBukuLamaNotaris', [PembuatanNomor::class, 'getBukuLamaNotaris']);
    Route::post('/getBukuPPAT', [PembuatanNomor::class, 'getBukuPPAT']);
    Route::post('/getBukuLegalisasi', [PembuatanNomor::class, 'getBukuLegalisasi']);
    Route::post('/getBukuWarmerking', [PembuatanNomor::class, 'getBukuWarmerking']);
    Route::get('/getBukuInvoiceTax', [PembuatanNomor::class, 'getBukuInvoiceTax']);
    Route::post('/getBukuSuratNotaris', [PembuatanNomor::class, 'getBukuSuratNotaris']);
    Route::post('/getBukuSuratPPAT', [PembuatanNomor::class, 'getBukuSuratPPAT']);
    Route::post('/getBukuPesanan', [PembuatanNomor::class, 'getBukuPesanan']);

    Route::post('/EditAktaNotaris', [PembuatanNomor::class, 'EditAktaNotaris']);
    Route::post('/EditSuratNotaris', [PembuatanNomor::class, 'EditSuratNotaris']);
    Route::post('/EditSuratPPAT', [PembuatanNomor::class, 'EditSuratPPAT']);
    Route::post('/EditAktaPPAT', [PembuatanNomor::class, 'EditAktaPPAT']);
    Route::post('/EditLegalisasi', [PembuatanNomor::class, 'EditLegalisasi']);
    Route::post('/EditWarmerking', [PembuatanNomor::class, 'EditWarmerking']);
    Route::post('/EditInvoiceTax', [PembuatanNomor::class, 'EditInvoiceTax']);

    Route::get('/getDataLayanan', [LayananController::class, 'getDataLayanan']);
    Route::post('/SimpanLayanan', [LayananController::class, 'SimpanLayanan']);
    Route::post('/DeleteLayanan', [LayananController::class, 'DeleteLayanan']);
    Route::get('/getDataDokumen', [LayananController::class, 'getDataDokumens']);
    Route::post('/getStandarDokumen', [LayananController::class, 'getStandarDokumen']);

    Route::post('/CariTagihan', [PembuatanNomor::class, 'CariTagihan']);
    Route::post('/UploadExcelNotaris', [PembuatanNomor::class, 'UploadExcelNotaris']);
    Route::post('/UploadExcelWarmerking', [PembuatanNomor::class, 'UploadExcelWarmerking']);
    Route::post('/UploadExcelLegalisasi', [PembuatanNomor::class, 'UploadExcelLegalisasi']);
    Route::post('/UploadExcelPPAT', [PembuatanNomor::class, 'UploadExcelPPAT']);

    Route::post('/getDataClient', [ClientController::class, 'getDataClient']);
    Route::post('/SimpanClientBaru', [ClientController::class, 'SimpanClientBaru']);

    Route::post('/UploadDokumenNotaris', [DokumenNotaris::class, 'UploadDokumenNotaris']);
    Route::post('/UploadDokumenWarmerking', [DokumenNotaris::class, 'UploadDokumenWarmerking']);
    Route::post('/UploadDokumenClient', [DokumenNotaris::class, 'UploadDokumenClient']);
    Route::post('/UploadDokumenLegalisasi', [DokumenNotaris::class, 'UploadDokumenLegalisasi']);
    Route::post('/UploadDokumenPPAT', [DokumenNotaris::class, 'UploadDokumenPPAT']);
    Route::post('/UploadSuratNotaris', [DokumenNotaris::class, 'UploadSuratNotaris']);
    Route::post('/UploadSuratPPAT', [DokumenNotaris::class, 'UploadSuratPPAT']);
    Route::post('/UploadTandaTerima', [DokumenNotaris::class, 'UploadTandaTerima']);

    Route::post('/StandarDokumenNotaris', [DokumenNotaris::class, 'StandarDokumenNotaris']);
    Route::post('/StandarDokumenWarmerkings', [DokumenNotaris::class, 'StandarDokumenWarmerkings']);
    Route::post('/StandarDokumenLegalisasis', [DokumenNotaris::class, 'StandarDokumenLegalisasis']);
    Route::post('/StandarDokumenPPAT', [DokumenNotaris::class, 'StandarDokumenPPAT']);

    Route::post('/UpdateDokumenNotaris', [DokumenNotaris::class, 'UpdateDokumenNotaris']);
    Route::post('/UpdateDokumenWarmerking', [DokumenNotaris::class, 'UpdateDokumenWarmerking']);
    Route::post('/UpdateDokumenLegalisasi', [DokumenNotaris::class, 'UpdateDokumenLegalisasi']);
    Route::post('/UpdateDokumenPPAT', [DokumenNotaris::class, 'UpdateDokumenPPAT']);

    Route::post('/DeleteDokumenNotaris', [DokumenNotaris::class, 'DeleteDokumenNotaris']);
    Route::post('/DeleteDokumenWarmerking', [DokumenNotaris::class, 'DeleteDokumenWarmerking']);
    Route::post('/DeleteDokumenLegalisasi', [DokumenNotaris::class, 'DeleteDokumenLegalisasi']);
    Route::post('/DeleteDokumenPPAT', [DokumenNotaris::class, 'DeleteDokumenPPAT']);
    Route::post('/DeleteSuratNotaris', [DokumenNotaris::class, 'DeleteSuratNotaris']);
    Route::post('/DeleteSuratPPAT', [DokumenNotaris::class, 'DeleteSuratPPAT']);
    Route::post('/DeleteTandaTerima', [DokumenNotaris::class, 'DeleteTandaTerima']);

    Route::post('/SimpanDokumenStandar', [DokumenNotaris::class, 'SimpanDokumenStandar']);
    Route::post('/DeleteDokumenStandar', [DokumenNotaris::class, 'DeleteDokumenStandar']);
    Route::post('/getDataDokumenClient', [DashboardController::class, 'getDataDokumenClient']);
    Route::post('/UpdateDokumenClient', [DokumenNotaris::class, 'UpdateDokumenClient']);
    Route::post('/DeleteDokumenClient', [DokumenNotaris::class, 'DeleteDokumenClient']);

    Route::post('/SimpanDetailOrder', [Order::class, 'SimpanDetailOrder']);
    Route::post('/UpdateStatusInvoice', [Order::class, 'UpdateStatusInvoice']);

    Route::post('/SimpanJadwal', [JadwalNotarisController::class, 'SimpanJadwalNotaris']);
    Route::post('/getJadwalNotaris', [JadwalNotarisController::class, 'getJadwalNotaris']);

    /*
    |--------------------------------------------------------------------------
    | PEMINJAMAN MINUTA
    |--------------------------------------------------------------------------
    */

    Route::get('/getPeminjamanMinuta', [PeminjamanMinutaController::class, 'index']);

    Route::post('/SimpanPeminjamanMinuta', [PeminjamanMinutaController::class, 'store']);

    Route::post('/UpdatePeminjamanMinuta/{id}', [PeminjamanMinutaController::class, 'update']);

    Route::post('/KembalikanPeminjamanMinuta/{id}', [PeminjamanMinutaController::class, 'kembalikan']);

    Route::post('/PerpanjangPeminjamanMinuta/{id}', [PeminjamanMinutaController::class, 'perpanjang']);
    Route::post('/TogglePeminjamanMinuta/{id}', [PeminjamanMinutaController::class, 'togglePengembalian']);

    Route::prefix('admin-work')->group(function () {
        Route::get('/reportorium-jobs', [AdminReportoriumController::class, 'reportoriumJobs']);
        Route::get('/asisten', [AdminReportoriumController::class, 'asisten']);
        Route::post('/reassign', [AdminReportoriumController::class, 'reassign']);
    });

    Route::prefix('document-access')->group(function () {
        Route::post('/request-download', [DocumentAccessController::class, 'requestDownload']);
        Route::get('/requests', [DocumentAccessController::class, 'listRequests']);
        Route::post('/requests/{id}/decision', [DocumentAccessController::class, 'decide']);
        Route::get('/download/{id}', [DocumentAccessController::class, 'download']);
    });


    Route::get('/CekTglAktaNotarisTerakhir', function () {
        $data = BukuNotaris::whereMonth('tgl_akta', date('m'))->orderBy('tgl_akta', 'DESC')->select('tgl_akta')->get()->first();

        $response = [
            'status' => true,
            'message' => 'Berhasil menampilkan',
            'data' => $data,
        ];

        return response($response, 200);
    });

    Route::get('/getPemenang', [Dashboard::class, 'getPemenang']);
    Route::get('/getTotalPekerjaan', [Dashboard::class, 'getTotalPekerjaan']);
    Route::get('/getGrafik', [Dashboard::class, 'getGrafik']);

    Route::post('/SearchData', [Pencarian::class, 'index']);
    Route::post('/getPencarianBukuNotaris', [Pencarian::class, 'getPencarianBukuNotaris']);
    Route::post('/getPencarianBukuWarmerking', [Pencarian::class, 'getPencarianBukuWarmerking']);
    Route::post('/getPencarianBukuLegalisasi', [Pencarian::class, 'getPencarianBukuLegalisasi']);
    Route::post('/getPencarianBukuPPAT', [Pencarian::class, 'getPencarianBukuPPAT']);
    Route::post('/getDokumenClient', [Pencarian::class, 'getDokumenClient']);

    /*
    |--------------------------------------------------------------------------
    | MANAGEMENT BANTEK (SERVICE BARU)
    |--------------------------------------------------------------------------
    */
    Route::prefix('bantek')->group(function () {
        Route::get('/', [BantekController::class, 'index']);
        Route::post('/create', [BantekController::class, 'store']);
        Route::post('/add-clients', [BantekController::class, 'addClients']);
        Route::post('/remove-client', [BantekController::class, 'removeClient']);
    });

    Route::prefix('chat')->group(function () {
        Route::get('/contacts', [EmployeeChatController::class, 'contacts']);
        Route::get('/unread-summary', [EmployeeChatController::class, 'unreadSummary']);
        Route::get('/messages', [EmployeeChatController::class, 'index']);
        Route::post('/messages', [EmployeeChatController::class, 'store']);
    });


    Route::get('/tanda-terima', [TandaTerimaController::class, 'index']);
    Route::get('/tanda-terima/{id}', [TandaTerimaController::class, 'show']);
    Route::post('/tanda-terima', [TandaTerimaController::class, 'store']);
    Route::put('/tanda-terima/{id}', [TandaTerimaController::class, 'update']);
    Route::delete('/tanda-terima/{id}', [TandaTerimaController::class, 'destroy']);
});
