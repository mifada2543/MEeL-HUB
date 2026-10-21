<?php
spl_autoload_register(function (string $class) {
    $map = [

        'System' => __DIR__ . '/core/System.php',
        'Uploader' => __DIR__ . '/core/Uploader.php',
        'Transcoder' => __DIR__ . '/core/Transcoder.php',
        'TranscoderBase' => __DIR__ . '/core/TranscoderBase.php',
        'DownloadService' => __DIR__ . '/../modules/transcoder/DownloadService.php',
        'EncodeService' => __DIR__ . '/../modules/transcoder/EncodeService.php',
        'TranscodeService' => __DIR__ . '/../modules/transcoder/TranscodeService.php',
        'MediaLibrary' => __DIR__ . '/media/MediaLibrary.php',
        'BookRepository' => __DIR__ . '/media/BookRepository.php',
        'BookUploader' => __DIR__ . '/media/BookUploader.php',
        'MediaViewer' => __DIR__ . '/media/MediaViewer.php',
        'MediaInteraction' => __DIR__ . '/media/MediaInteraction.php',
        'GarbageCollector' => __DIR__ . '/core/GarbageCollector.php',
        'RateLimiter' => __DIR__ . '/auth/RateLimiter.php',
        'Notification' => __DIR__ . '/core/Notification.php',
        'MeelCoin' => __DIR__ . '/core/MeelCoin.php',
        'SsrfGuard' => __DIR__ . '/auth/SsrfGuard.php',
        'ValidatingProxy' => __DIR__ . '/auth/ValidatingProxy.php',
        'SearchEngine' => __DIR__ . '/media/SearchEngine.php',
        'SwPrecache' => __DIR__ . '/core/SwPrecache.php',
        // CATATAN: entri 'DriveService' -> drive/DriveService.php DIHAPUS
        // (T19). Kelas `DriveService` tidak pernah ada: berkas itu memuat
        // StorageNotMountedException, DriveUserContext, DriveStorage, dan
        // DriveViewRenderer. Tidak ada kode yang memakainya -- halaman drive
        // me-require berkasnya langsung lalu memakai tiga kelas tadi.
        // Harmless selama autoloader hanya menyala saat namanya disebut, tapi
        // `new DriveService(...)` di masa depan akan me-require berkas itu
        // lalu fatal "class not found" -- dengan pesan yang menunjuk berkas
        // yang salah. Ditangkap ClassFileMapTest.
    ];

    if (isset($map[$class])) {
        require_once $map[$class];
    }
});

/* reference build: MEeL-C5H5N5O [585c5509c9a6ae80] */
