<?php
echo "=== COIN DEBUG TEST ===\n";

// 1. Backend API Test
echo "\n1. Backend API Test:\n";
try {
    $url = "http://localhost/dark/backend/admin/coins.php?action=list";
    $response = file_get_contents($url);
    echo "Response: " . $response . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// 2. Direct Database Test
echo "\n2. Direct Database Test:\n";
try {
    require_once 'backend/config.php';
    $conn = db_connect();
    
    // Coins tablosunu kontrol et
    $sql = "SHOW TABLES LIKE 'coins'";
    $result = $conn->query($sql);
    if ($result->rowCount() > 0) {
        echo "✅ Coins tablosu mevcut\n";
        
        // Tablo yapısını kontrol et
        $sql = "DESCRIBE coins";
        $result = $conn->query($sql);
        echo "Tablo yapısı:\n";
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
        
        // Tüm coinleri listele
        $sql = "SELECT * FROM coins";
        $result = $conn->query($sql);
        echo "\nTüm coinler:\n";
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            echo "ID: " . $row['id'] . ", Ad: " . $row['coin_adi'] . ", Kod: " . $row['coin_kodu'] . ", Tip: " . ($row['coin_type'] ?? 'YOK') . "\n";
        }
        
        // Manuel coinleri listele
        $sql = "SELECT * FROM coins WHERE coin_type = 'manuel'";
        $result = $conn->query($sql);
        echo "\nManuel coinler:\n";
        $manuel_count = 0;
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            echo "ID: " . $row['id'] . ", Ad: " . $row['coin_adi'] . ", Kod: " . $row['coin_kodu'] . "\n";
            $manuel_count++;
        }
        echo "Manuel coin sayısı: " . $manuel_count . "\n";
        
    } else {
        echo "❌ Coins tablosu bulunamadı\n";
    }
    
} catch (Exception $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}

// 3. Coin Ekleme Test
echo "\n3. Coin Ekleme Test:\n";
try {
    require_once 'backend/config.php';
    $conn = db_connect();
    
    // Test coin ekle
    $sql = "INSERT INTO coins (coin_adi, coin_kodu, current_price, coin_type, is_active) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $result = $stmt->execute(['Test Coin', 'TEST', 100.50, 'manuel', 1]);
    
    if ($result) {
        echo "✅ Test coin eklendi (ID: " . $conn->lastInsertId() . ")\n";
    } else {
        echo "❌ Test coin eklenemedi\n";
    }
    
} catch (Exception $e) {
    echo "Insert Error: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETED ===\n";
?>
