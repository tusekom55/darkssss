<?php
// Para çekme talepleri API test dosyası
header('Content-Type: text/html; charset=utf-8');

echo "<h2>Para Çekme Talepleri API Test</h2>";
echo "<pre>";

// Veritabanı bağlantısını test et
try {
    require_once 'backend/config.php';
    echo "✅ Veritabanı bağlantısı başarılı\n\n";
    
    // Para çekme talepleri tablosunu kontrol et
    echo "📋 Para çekme talepleri tablosu kontrolü:\n";
    $sql = "SHOW TABLES LIKE 'para_cekme_talepleri'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $tableExists = $stmt->fetch();
    
    if ($tableExists) {
        echo "✅ para_cekme_talepleri tablosu mevcut\n";
        
        // Tablo yapısını kontrol et
        echo "\n📊 Tablo yapısı:\n";
        $sql = "DESCRIBE para_cekme_talepleri";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($columns as $column) {
            echo "  - {$column['Field']} ({$column['Type']})\n";
        }
        
        // Verileri listele
        echo "\n📋 Mevcut veriler:\n";
        $sql = "SELECT COUNT(*) as toplam FROM para_cekme_talepleri";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $count = $stmt->fetchColumn();
        
        echo "  Toplam kayıt sayısı: {$count}\n\n";
        
        if ($count > 0) {
            $sql = "SELECT 
                        pct.id, pct.user_id, pct.yontem, pct.tutar, 
                        pct.iban, pct.hesap_sahibi, pct.durum, pct.tarih,
                        u.username
                    FROM para_cekme_talepleri pct
                    LEFT JOIN users u ON pct.user_id = u.id
                    ORDER BY pct.tarih DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($withdrawals as $withdrawal) {
                echo "  ID: {$withdrawal['id']}\n";
                echo "  Kullanıcı: {$withdrawal['username']} (ID: {$withdrawal['user_id']})\n";
                echo "  Yöntem: {$withdrawal['yontem']}\n";
                echo "  Tutar: ₺{$withdrawal['tutar']}\n";
                echo "  IBAN: {$withdrawal['iban']}\n";
                echo "  Hesap Sahibi: {$withdrawal['hesap_sahibi']}\n";
                echo "  Durum: {$withdrawal['durum']}\n";
                echo "  Tarih: {$withdrawal['tarih']}\n";
                echo "  ---\n";
            }
        }
        
        // Admin API'sini test et
        echo "\n🔗 Admin API testi:\n";
        echo "API URL: backend/admin/withdrawals.php?action=list\n";
        
        // API'yi çağır
        $apiUrl = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/backend/admin/withdrawals.php?action=list';
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Content-Type: application/json',
                'timeout' => 10
            ]
        ]);
        
        $apiResponse = @file_get_contents($apiUrl, false, $context);
        
        if ($apiResponse !== false) {
            echo "✅ API yanıtı alındı\n";
            $apiData = json_decode($apiResponse, true);
            
            if ($apiData && isset($apiData['success'])) {
                if ($apiData['success']) {
                    echo "✅ API başarılı - " . count($apiData['data']) . " kayıt döndü\n";
                    echo "API Yanıtı:\n" . json_encode($apiData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                } else {
                    echo "❌ API hatası: " . ($apiData['error'] ?? 'Bilinmeyen hata') . "\n";
                }
            } else {
                echo "❌ API yanıtı geçersiz format\n";
                echo "Ham yanıt: " . $apiResponse . "\n";
            }
        } else {
            echo "❌ API'ye erişim hatası\n";
            echo "HTTP hata detayları: " . print_r(error_get_last(), true) . "\n";
        }
        
        // Users tablosunu kontrol et
        echo "\n👥 Users tablosu kontrolü:\n";
        $sql = "SELECT id, username, role, balance FROM users WHERE role = 'user' LIMIT 3";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($users as $user) {
            echo "  ID: {$user['id']}, Username: {$user['username']}, Balance: ₺{$user['balance']}\n";
        }
        
    } else {
        echo "❌ para_cekme_talepleri tablosu bulunamadı\n";
        echo "fix_withdrawal_table.sql dosyasını veritabanında çalıştırın!\n";
    }
    
} catch (Exception $e) {
    echo "❌ Hata: " . $e->getMessage() . "\n";
    echo "Dosya: " . $e->getFile() . "\n";
    echo "Satır: " . $e->getLine() . "\n";
}

echo "</pre>";

echo "<hr>";
echo "<h3>Çözüm Adımları:</h3>";
echo "<ol>";
echo "<li><strong>fix_withdrawal_table.sql</strong> dosyasını hosting panelindeki veritabanında çalıştırın</li>";
echo "<li>Admin paneli açın ve Para Çekme Talepleri bölümüne gidin</li>";
echo "<li>Eğer hala görünmüyorsa, tarayıcı konsolunda hata olup olmadığını kontrol edin</li>";
echo "<li>API testi bu sayfada ✅ işareti gösteriyorsa sorun frontend'de olabilir</li>";
echo "</ol>";

echo "<p><a href='admin-panel.html' target='_blank'>Admin Paneli Aç</a> | ";
echo "<a href='backend/admin/withdrawals.php?action=list' target='_blank'>API'yi Doğrudan Test Et</a></p>";
?>
