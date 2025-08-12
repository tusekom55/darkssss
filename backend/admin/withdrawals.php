<?php
session_start();
require_once '../config.php';

// Test modu - session kontrolü olmadan çalışır
// Production'da bu satırları açın:
// if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
//     http_response_code(403);
//     echo json_encode(['error' => 'Yetkisiz erişim']);
//     exit;
// }

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// OPTIONS request için
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// PDO bağlantısını kullan
try {
    $pdo = db_connect();
} catch (Exception $e) {
    echo json_encode(['error' => 'Veritabanı bağlantı hatası: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
        // Para çekme taleplerini listele
        $sql = "SELECT 
                    pct.*, 
                    u.username, u.email, u.telefon, u.ad_soyad,
                    a.username as admin_username
                FROM para_cekme_talepleri pct
                JOIN users u ON pct.user_id = u.id
                LEFT JOIN users a ON pct.onaylayan_admin_id = a.id
                ORDER BY pct.tarih DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $withdrawals]);
        break;
        
    case 'approve':
        // Para çekme talebini onayla (GET ve POST destekli)
        $withdrawal_id = $_POST['withdrawal_id'] ?? $_GET['withdrawal_id'] ?? 0;
        $aciklama = $_POST['aciklama'] ?? $_GET['aciklama'] ?? '';
        
        // Önce talebi kontrol et (durum kontrolü olmadan)
        $sql = "SELECT * FROM para_cekme_talepleri WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$withdrawal_id]);
        $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$withdrawal) {
            echo json_encode(['error' => 'Talep bulunamadı (ID: ' . $withdrawal_id . ')']);
            exit;
        }
        
        // Durum kontrolü
        if ($withdrawal['durum'] !== 'beklemede') {
            echo json_encode([
                'error' => 'Talep zaten işlenmiş', 
                'current_status' => $withdrawal['durum'],
                'withdrawal_data' => $withdrawal
            ]);
            exit;
        }
        
        // Kullanıcının bakiyesini kontrol et
        $sql = "SELECT balance FROM users WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$withdrawal['user_id']]);
        $user_balance = $stmt->fetchColumn();
        
        if ($user_balance < $withdrawal['tutar']) {
            echo json_encode(['error' => 'Kullanıcının yeterli bakiyesi yok']);
            exit;
        }
        
        // Transaction başlat
        $pdo->beginTransaction();
        
        try {
            // Talebi onayla
            $sql = "UPDATE para_cekme_talepleri SET 
                    durum = 'onaylandi', 
                    onay_tarihi = NOW(), 
                    onaylayan_admin_id = ?,
                    admin_aciklama = ?
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_SESSION['user_id'] ?? 1, $aciklama, $withdrawal_id]);
            
            // Kullanıcının bakiyesini güncelle
            $sql = "UPDATE users SET balance = balance - ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$withdrawal['tutar'], $withdrawal['user_id']]);
            
            // Faturalar tablosunu oluştur (yoksa)
            $create_table_sql = "CREATE TABLE IF NOT EXISTS faturalar (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                islem_tipi VARCHAR(50) NOT NULL,
                islem_id INT DEFAULT 0,
                fatura_no VARCHAR(100) NOT NULL,
                tutar DECIMAL(10,2) NOT NULL,
                kdv_orani DECIMAL(5,2) DEFAULT 18.00,
                kdv_tutari DECIMAL(10,2) DEFAULT 0.00,
                toplam_tutar DECIMAL(10,2) NOT NULL,
                aciklama TEXT,
                tarih TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            $pdo->exec($create_table_sql);
            
            // Otomatik fatura oluştur
            $fatura_no = 'FTR-' . date('Ymd') . '-' . str_pad($withdrawal['user_id'], 4, '0', STR_PAD_LEFT) . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            $kdv_orani = 18;
            $kdv_tutari = $withdrawal['tutar'] * ($kdv_orani / 100);
            $toplam_tutar = $withdrawal['tutar'] + $kdv_tutari;
            $fatura_aciklama = "Para çekme işlemi - {$withdrawal['yontem']} - {$withdrawal['iban']}";
            
            $sql = "INSERT INTO faturalar (user_id, islem_tipi, islem_id, fatura_no, tutar, kdv_orani, kdv_tutari, toplam_tutar, aciklama) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $withdrawal['user_id'],
                'para_cekme',
                $withdrawal_id,
                $fatura_no,
                $withdrawal['tutar'],
                $kdv_orani,
                $kdv_tutari,
                $toplam_tutar,
                $fatura_aciklama
            ]);
            
            $fatura_id = $pdo->lastInsertId();
            
            // İşlem geçmişine ekle (opsiyonel tablo)
            try {
                $sql = "INSERT INTO kullanici_islem_gecmisi 
                        (user_id, islem_tipi, islem_detayi, tutar, onceki_bakiye, sonraki_bakiye) 
                        VALUES (?, 'para_cekme', ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $withdrawal['user_id'],
                    "Para çekme onaylandı - {$withdrawal['yontem']} - Fatura: {$fatura_no}",
                    $withdrawal['tutar'],
                    $user_balance,
                    $user_balance - $withdrawal['tutar']
                ]);
            } catch (Exception $e) {
                // İşlem geçmişi tablosu yoksa pas geç
            }
            
            $pdo->commit();
            echo json_encode([
                'success' => true, 
                'message' => 'Para çekme talebi onaylandı ve fatura oluşturuldu',
                'fatura_id' => $fatura_id,
                'fatura_no' => $fatura_no
            ]);
            
        } catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(['error' => 'İşlem başarısız: ' . $e->getMessage()]);
        }
        break;
        
    case 'reject':
        // Para çekme talebini reddet (GET ve POST destekli)
        $withdrawal_id = $_POST['withdrawal_id'] ?? $_GET['withdrawal_id'] ?? 0;
        $aciklama = $_POST['aciklama'] ?? $_GET['aciklama'] ?? '';
        
        // Talebi getir
        $sql = "SELECT * FROM para_cekme_talepleri WHERE id = ? AND durum = 'beklemede'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$withdrawal_id]);
        $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$withdrawal) {
            echo json_encode(['error' => 'Talep bulunamadı veya zaten işlenmiş']);
            exit;
        }
        
        // Talebi reddet
        $sql = "UPDATE para_cekme_talepleri SET 
                durum = 'reddedildi', 
                onay_tarihi = NOW(), 
                onaylayan_admin_id = ?,
                admin_aciklama = ?
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$_SESSION['user_id'] ?? 1, $aciklama, $withdrawal_id]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Para çekme talebi reddedildi']);
        } else {
            echo json_encode(['error' => 'İşlem başarısız']);
        }
        break;
        
    case 'detail':
        // Talep detayları
        $withdrawal_id = $_GET['withdrawal_id'] ?? 0;
        
        $sql = "SELECT 
                    pct.*, 
                    u.username, u.email, u.telefon, u.ad_soyad, u.balance,
                    a.username as admin_username
                FROM para_cekme_talepleri pct
                JOIN users u ON pct.user_id = u.id
                LEFT JOIN users a ON pct.onaylayan_admin_id = a.id
                WHERE pct.id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$withdrawal_id]);
        $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$withdrawal) {
            echo json_encode(['error' => 'Talep bulunamadı']);
            exit;
        }
        
        echo json_encode(['success' => true, 'data' => $withdrawal]);
        break;
        
    default:
        echo json_encode(['error' => 'Geçersiz işlem']);
        break;
}
?>
