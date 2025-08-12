<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// CORS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config.php';

// Session kontrolü
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Oturum bulunamadı']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

try {
    // Config.php'deki db_connect fonksiyonunu kullan
    $pdo = db_connect();
    
    // Withdrawals tablosunu kontrol et ve oluştur
    $checkTable = $pdo->query("SHOW TABLES LIKE 'withdrawals'");
    if ($checkTable->rowCount() == 0) {
        $createTable = "
        CREATE TABLE withdrawals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            yontem VARCHAR(50) NOT NULL,
            tutar DECIMAL(15,2) NOT NULL,
            detay_bilgiler JSON,
            aciklama TEXT,
            durum ENUM('beklemede', 'onaylandi', 'reddedildi') DEFAULT 'beklemede',
            tarih TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            admin_not TEXT,
            islem_tarihi TIMESTAMP NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $pdo->exec($createTable);
    }

    switch ($action) {
        case 'create':
            // Para çekme talebi oluştur
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Sadece POST metoduna izin verilir');
            }

            $yontem = $_POST['yontem'] ?? '';
            $tutar = floatval($_POST['tutar'] ?? 0);
            $detay_bilgiler = $_POST['detay_bilgiler'] ?? '{}';
            $aciklama = $_POST['aciklama'] ?? '';

            // Validasyon
            if (empty($yontem)) {
                throw new Exception('Çekme yöntemi gereklidir');
            }

            if ($tutar < 100) {
                throw new Exception('Minimum çekme tutarı ₺100\'dür');
            }

            if ($tutar > 50000) {
                throw new Exception('Maksimum çekme tutarı ₺50,000\'dir');
            }

            // Kullanıcı bakiyesini kontrol et
            $userStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
            $userStmt->execute([$user_id]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception('Kullanıcı bulunamadı');
            }

            if ($user['balance'] < $tutar) {
                throw new Exception('Yetersiz bakiye');
            }

            // Para çekme talebini kaydet
            $stmt = $pdo->prepare("
                INSERT INTO withdrawals (user_id, yontem, tutar, detay_bilgiler, aciklama) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $user_id, 
                $yontem, 
                $tutar, 
                $detay_bilgiler, 
                $aciklama
            ]);

            if ($result) {
                $withdrawal_id = $pdo->lastInsertId();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Para çekme talebi başarıyla oluşturuldu',
                    'data' => [
                        'id' => $withdrawal_id,
                        'tutar' => $tutar,
                        'yontem' => $yontem,
                        'durum' => 'beklemede'
                    ]
                ]);
            } else {
                throw new Exception('Para çekme talebi oluşturulamadı');
            }
            break;

        case 'list':
            // Kullanıcının para çekme taleplerini listele
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    yontem,
                    tutar,
                    detay_bilgiler,
                    aciklama,
                    durum,
                    tarih,
                    admin_not,
                    islem_tarihi
                FROM withdrawals 
                WHERE user_id = ? 
                ORDER BY tarih DESC 
                LIMIT 20
            ");
            
            $stmt->execute([$user_id]);
            $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // JSON formatı düzenle
            foreach ($withdrawals as &$withdrawal) {
                if (!empty($withdrawal['detay_bilgiler'])) {
                    $withdrawal['detay_bilgiler'] = json_decode($withdrawal['detay_bilgiler'], true);
                }
                
                $withdrawal['tutar'] = floatval($withdrawal['tutar']);
                $withdrawal['tarih_formatted'] = date('d.m.Y H:i', strtotime($withdrawal['tarih']));
            }

            echo json_encode([
                'success' => true,
                'data' => $withdrawals,
                'count' => count($withdrawals)
            ]);
            break;

        case 'status':
            // Belirli bir talebin durumunu getir
            $withdrawal_id = $_GET['id'] ?? 0;
            
            if (!$withdrawal_id) {
                throw new Exception('Talep ID gereklidir');
            }

            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    yontem,
                    tutar,
                    durum,
                    tarih,
                    admin_not,
                    islem_tarihi
                FROM withdrawals 
                WHERE id = ? AND user_id = ?
            ");
            
            $stmt->execute([$withdrawal_id, $user_id]);
            $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$withdrawal) {
                throw new Exception('Talep bulunamadı');
            }

            $withdrawal['tutar'] = floatval($withdrawal['tutar']);
            $withdrawal['tarih_formatted'] = date('d.m.Y H:i', strtotime($withdrawal['tarih']));

            echo json_encode([
                'success' => true,
                'data' => $withdrawal
            ]);
            break;

        default:
            throw new Exception('Geçersiz işlem');
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Veritabanı hatası: ' . $e->getMessage()
    ]);
}
?>
