<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';

try {
    db()->query('SELECT 1');
} catch (Throwable) {
    http_response_code(503);
    ?>
    <!doctype html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Torks & Hyll · Setup required</title><link rel="stylesheet" href="assets/css/app.css"></head>
    <body class="auth-shell"><div class="auth-page"><section class="auth-brand"><div class="brand-kicker">TORKS & HYLL / STORE OPERATIONS</div><div class="auth-brand-copy"><span class="brand-mark large">T<span>&</span>H</span><h1>Your counter,<br>in control.</h1><p>One quick setup before opening time.</p></div><div class="auth-quote">Secure Retail Management System</div></section><section class="auth-form-wrap"><div class="auth-form"><span class="eyebrow">DATABASE SETUP</span><h2>Connect your shop.</h2><p class="muted">Copy <strong>config/env.example.php</strong> to <strong>config/env.php</strong>, enter the MySQL details from InfinityFree, then import the three SQL files listed in IMPORT.md.</p><p class="form-foot">No database credentials are stored in the application files.</p></div></section></div></body></html>
    <?php
    exit;
}

$page = (string)($_GET['page'] ?? (current_user() ? 'dashboard' : 'login'));
$action = (string)($_POST['action'] ?? '');

function back_to(string $page): never
{
    redirect($page);
}

function category_id(string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    db()->prepare('INSERT INTO categories (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')
        ->execute([$name]);
    return (int)db()->lastInsertId();
}

function record_stock_movement(PDO $pdo, int $productId, float $qty, float $before, float $after, string $reason, string $type = 'adjustment', string $reference = ''): void
{
    $pdo->prepare('INSERT INTO stock_movements (product_id,type,qty,stock_before,stock_after,reason,reference,user_id) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$productId, $type, $qty, $before, $after, $reason, $reference, current_user()['id']]);
}

function validate_import(array $preview, array $mapping, string $duplicateMode): array
{
    $pdo = db();
    $skuIndex = array_search('sku', $mapping, true);
    $nameIndex = array_search('name', $mapping, true);
    $errors = []; $valid = []; $seen = [];
    if ($skuIndex === false || $nameIndex === false) {
        return ['errors' => [[0, 'Map both SKU and Name before importing.']], 'valid' => []];
    }
    $value = static function(string $field, array $row) use ($mapping): string {
        $index = array_search($field, $mapping, true);
        return $index === false ? '' : trim((string)($row[$index] ?? ''));
    };
    foreach ($preview['rows'] as $rowIndex => $row) {
        $sku = $value('sku', $row); $name = $value('name', $row);
        if ($sku === '' || $name === '') { $errors[] = [$rowIndex + 2, 'SKU and name are required']; continue; }
        if (isset($seen[$sku])) { $errors[] = [$rowIndex + 2, 'Duplicate SKU in file']; continue; }
        $seen[$sku] = true;
        $fields = ['purchase_price','selling_price','stock','min_stock']; $numbers=[]; $bad=false;
        foreach ($fields as $field) {
            $raw=$value($field,$row); $numbers[$field]=$raw;
            if ($raw !== '' && (!is_numeric($raw) || (float)$raw < 0)) { $errors[] = [$rowIndex + 2, $field . ' must be a non-negative number']; $bad=true; }
        }
        if ($bad) continue;
        $existing=$pdo->prepare('SELECT id,current_stock FROM products WHERE sku=?'); $existing->execute([$sku]); $product=$existing->fetch();
        if ($product && $duplicateMode === 'skip') { $valid[]=['row'=>$rowIndex+2,'sku'=>$sku,'action'=>'skip']; continue; }
        $valid[]=['row'=>$rowIndex+2,'sku'=>$sku,'action'=>$product?'update':'create'];
    }
    return ['errors'=>$errors,'valid'=>$valid];
}

function import_rows_from_file(string $path, string $extension): array
{
    if ($extension === 'csv') {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('The uploaded file could not be read.');
        }
        $headers = fgetcsv($handle);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, static fn($v) => trim((string)$v) !== '')) === 0) {
                continue;
            }
            $rows[] = array_pad($row, count($headers), '');
        }
        fclose($handle);
        return ['headers' => array_map(static fn($v) => trim((string)$v), $headers ?: []), 'rows' => $rows];
    }

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('XLSX support is unavailable on this PHP installation. Please export the workbook as CSV.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('The XLSX file could not be opened.');
    }
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml) {
        $xml = simplexml_load_string($sharedXml);
        foreach ($xml?->si ?? [] as $si) {
            $parts = [];
            foreach ($si->t ?? [] as $text) {
                $parts[] = (string)$text;
            }
            $shared[] = implode('', $parts);
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) {
        $zip->close();
        throw new RuntimeException('The XLSX file has no first worksheet.');
    }
    $sheet = simplexml_load_string($sheetXml);
    $rawRows = [];
    foreach ($sheet?->sheetData?->row ?? [] as $rowNode) {
        $values = [];
        foreach ($rowNode->c ?? [] as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/([A-Z]+)/', $ref, $match);
            $column = 0;
            foreach (str_split($match[1] ?? 'A') as $letter) {
                $column = $column * 26 + ord($letter) - 64;
            }
            $value = (string)($cell->v ?? '');
            if ((string)$cell['t'] === 's') {
                $value = $shared[(int)$value] ?? '';
            }
            $values[$column - 1] = $value;
        }
        if ($values) {
            ksort($values);
            $rawRows[] = array_values($values);
        }
    }
    $zip->close();
    $headers = array_map(static fn($v) => trim((string)$v), array_shift($rawRows) ?: []);
    return ['headers' => $headers, 'rows' => $rawRows];
}

function import_default_field(string $header): string
{
    $h = strtolower(preg_replace('/[^a-z0-9]+/', '_', trim($header)) ?? '');
    $aliases = [
        'sku' => ['sku', 'product_code', 'code', 'item_code'],
        'barcode' => ['barcode', 'bar_code', 'upc', 'ean'],
        'name' => ['name', 'product_name', 'item', 'item_name', 'description'],
        'category' => ['category', 'category_name', 'department'],
        'purchase_price' => ['purchase_price', 'cost', 'cost_price', 'buying_price'],
        'selling_price' => ['selling_price', 'price', 'sale_price', 'retail_price'],
        'stock' => ['stock', 'quantity', 'qty', 'current_stock', 'opening_stock'],
        'min_stock' => ['min_stock', 'minimum_stock', 'reorder_level'],
        'unit' => ['unit', 'uom', 'measure'],
    ];
    foreach ($aliases as $field => $names) {
        if (in_array($h, $names, true)) {
            return $field;
        }
    }
    return '';
}

function next_invoice_number(PDO $pdo): string
{
    $today = date('Y-m-d');
    $pdo->prepare(
        'INSERT INTO invoice_counters (invoice_date, last_number) VALUES (?, 1)
         ON DUPLICATE KEY UPDATE last_number = last_number + 1'
    )->execute([$today]);
    $stmt = $pdo->prepare('SELECT last_number FROM invoice_counters WHERE invoice_date = ?');
    $stmt->execute([$today]);
    return 'INV-' . date('Ymd') . '-' . str_pad((string)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
}

try {
    if ($page === 'logout') {
        logout_user();
        redirect('login');
    }

    if ($action === 'login') {
        verify_csrf();
        if (login_user(post_string('email'), (string)($_POST['password'] ?? ''))) {
            redirect('dashboard');
        }
        flash('error', 'The email or password is incorrect, or this account is locked.');
        redirect('login');
    }

    if ($page !== 'login') {
        require_login();
    }

    if ($page === 'import' && ($_GET['action'] ?? '') === 'download_errors') {
        require_manager();
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="import-errors.csv"');
        $out = fopen('php://output', 'wb');
        fputcsv($out, ['row', 'error']);
        foreach ((array)($_SESSION['import_errors'] ?? []) as $errorRow) {
            fputcsv($out, $errorRow);
        }
        fclose($out);
        exit;
    }

    if ($action !== '') {
        verify_csrf();
        switch ($action) {
            case 'product_save':
                require_manager();
                $id = (int)($_POST['id'] ?? 0);
                $sku = post_string('sku'); $name = post_string('name');
                if ($sku === '' || $name === '') throw new InvalidArgumentException('SKU and product name are required.');
                $category = category_id(post_string('category'));
                $newStock = post_float('stock');
                if ($newStock < 0) throw new InvalidArgumentException('Stock cannot be negative.');
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $values = [$sku, post_string('barcode') ?: null, $name, post_string('description'), $category, post_float('purchase_price'), post_float('selling_price'), $newStock, post_float('min_stock'), post_string('unit', 'pcs')];
                    if ($id > 0) {
                        $oldStmt=$pdo->prepare('SELECT current_stock FROM products WHERE id=? FOR UPDATE'); $oldStmt->execute([$id]); $old=(float)$oldStmt->fetchColumn();
                        $pdo->prepare('UPDATE products SET sku=?, barcode=?, name=?, description=?, category_id=?, purchase_price=?, selling_price=?, current_stock=?, minimum_stock=?, unit=? WHERE id=?')->execute([...$values, $id]);
                        if (abs($newStock-$old)>0.000001) record_stock_movement($pdo,$id,$newStock-$old,$old,$newStock,'Stock changed while editing product');
                        audit('update', 'product', $id); flash('success', 'Product updated.');
                    } else {
                        $pdo->prepare('INSERT INTO products (sku, barcode, name, description, category_id, purchase_price, selling_price, current_stock, minimum_stock, unit) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($values);
                        $newId=(int)$pdo->lastInsertId();
                        record_stock_movement($pdo,$newId,$newStock,0,$newStock,'Opening stock','opening');
                        audit('create','product',$newId); flash('success','Product added to inventory.');
                    }
                    $pdo->commit();
                } catch (Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
                redirect('inventory');

            case 'product_delete':
                require_manager();
                $id = (int)($_POST['id'] ?? 0);
                db()->prepare('UPDATE products SET is_active = 0 WHERE id = ?')->execute([$id]);
                audit('deactivate', 'product', $id);
                flash('success', 'Product removed from the active inventory.');
                redirect('inventory');

            case 'stock_adjust':
                require_manager();
                $id = (int)($_POST['id'] ?? 0);
                $qty = post_float('qty');
                $reason = post_string('reason');
                if ($reason === '' || $qty == 0) {
                    throw new InvalidArgumentException('Enter a non-zero adjustment and a reason.');
                }
                $pdo = db();
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT current_stock FROM products WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $before = (float)$stmt->fetchColumn();
                $after = $before + $qty;
                if ($after < 0) {
                    throw new InvalidArgumentException('Stock cannot become negative.');
                }
                $pdo->prepare('UPDATE products SET current_stock = ? WHERE id = ?')->execute([$after, $id]);
                $pdo->prepare('INSERT INTO stock_movements (product_id,type,qty,stock_before,stock_after,reason,user_id) VALUES (?,\'adjustment\',?,?,?,?,?)')
                    ->execute([$id, $qty, $before, $after, $reason, current_user()['id']]);
                $pdo->commit();
                flash('success', 'Stock adjusted.');
                redirect('inventory');

            case 'user_save':
                require_manager();
                $id = (int)($_POST['id'] ?? 0);
                $first = post_string('first_name');
                $last = post_string('last_name');
                $email = strtolower(post_string('email'));
                $role = post_string('role', 'cashier');
                if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['manager', 'cashier'], true)) {
                    throw new InvalidArgumentException('Enter a valid name, email, and role.');
                }
                $password = (string)($_POST['password'] ?? '');
                if ($id > 0) {
                    $sql = 'UPDATE users SET first_name=?, last_name=?, email=?, phone=?, role=?' . ($password !== '' ? ', password_hash=?' : '') . ' WHERE id=?';
                    $args = [$first, $last, $email, post_string('phone'), $role];
                    if ($password !== '') $args[] = password_hash($password, PASSWORD_DEFAULT);
                    $args[] = $id;
                    db()->prepare($sql)->execute($args);
                    flash('success', 'Team member updated.');
                } else {
                    if (strlen($password) < 8) throw new InvalidArgumentException('New passwords must be at least 8 characters.');
                    $employee = 'EMP-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                    db()->prepare('INSERT INTO users (employee_id, first_name, last_name, email, phone, password_hash, role) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$employee, $first, $last, $email, post_string('phone'), password_hash($password, PASSWORD_DEFAULT), $role]);
                    flash('success', 'Team member added.');
                }
                redirect('users');

            case 'user_toggle':
                require_manager();
                $id = (int)($_POST['id'] ?? 0);
                $target = db()->prepare('SELECT role, is_active FROM users WHERE id=?');
                $target->execute([$id]);
                $targetUser = $target->fetch();
                if (!$targetUser) throw new InvalidArgumentException('User not found.');
                if ((int)$targetUser['is_active'] === 1 && $targetUser['role'] === 'manager') {
                    $count = (int)db()->query("SELECT COUNT(*) FROM users WHERE role='manager' AND is_active=1")->fetchColumn();
                    if ($count <= 1) throw new InvalidArgumentException('The last active manager cannot be deactivated.');
                }
                db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
                flash('success', 'User access updated.');
                redirect('users');

            case 'category_save':
                require_manager();
                $id=(int)($_POST['id']??0); $name=post_string('name'); if($name==='') throw new InvalidArgumentException('Category name is required.');
                if($id>0) db()->prepare('UPDATE categories SET name=? WHERE id=?')->execute([$name,$id]); else db()->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
                flash('success',$id?'Category updated.':'Category added.'); redirect('categories');

            case 'category_delete':
                require_manager(); $id=(int)($_POST['id']??0); db()->prepare('DELETE FROM categories WHERE id=?')->execute([$id]); flash('success','Category removed.'); redirect('categories');

            case 'settings_save':
                require_manager();
                $logoPath = (string)setting_value('logo_path', '');
                if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    if ((int)$_FILES['logo']['size'] > 2 * 1024 * 1024) throw new InvalidArgumentException('Logo must be smaller than 2 MB.');
                    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['logo']['tmp_name']);
                    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
                    if (!isset($allowed[$mime])) throw new InvalidArgumentException('Logo must be PNG, JPG, or WEBP.');
                    $filename = 'logo-' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
                    if (!is_dir(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads', 0750, true);
                    move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__ . '/uploads/' . $filename);
                    $logoPath = 'uploads/' . $filename;
                }
                db()->prepare('UPDATE settings SET business_name=?, address=?, phone=?, email=?, tin=?, vat_enabled=?, vat_rate=?, receipt_header=?, receipt_footer=?, currency=?, timezone=?, stock_override_enabled=?, logo_path=? WHERE id=1')
                    ->execute([post_string('business_name', 'Torks & Hyll'), post_string('address'), post_string('phone'), post_string('email'), post_string('tin'), isset($_POST['vat_enabled']) ? 1 : 0, post_float('vat_rate'), post_string('receipt_header'), post_string('receipt_footer'), post_string('currency', 'GHS'), post_string('timezone', 'Africa/Accra'), isset($_POST['stock_override_enabled']) ? 1 : 0, $logoPath]);
                flash('success', 'Settings saved.');
                redirect('settings');

            case 'cart_add':
                $id = (int)($_POST['product_id'] ?? 0);
                $qty = max(0.001, post_float('qty', 1));
                $stmt = db()->prepare('SELECT id, current_stock FROM products WHERE id=? AND is_active=1');
                $stmt->execute([$id]);
                $product = $stmt->fetch();
                if (!$product) throw new InvalidArgumentException('Product not found.');
                $cart = $_SESSION['cart'] ?? [];
                $cart[$id] = min((float)$product['current_stock'], (float)($cart[$id] ?? 0) + $qty);
                $_SESSION['cart'] = $cart;
                flash('success', 'Added to cart.');
                redirect('pos');

            case 'cart_update':
                $cart = $_SESSION['cart'] ?? [];
                foreach ((array)($_POST['qty'] ?? []) as $id => $qty) {
                    $qty = max(0, (float)$qty);
                    if ($qty === 0) unset($cart[(int)$id]); else $cart[(int)$id] = $qty;
                }
                $_SESSION['cart'] = $cart;
                flash('success', 'Cart updated.');
                redirect('pos');

            case 'cart_clear':
                unset($_SESSION['cart']);
                redirect('pos');

            case 'sale_complete':
                $cart=$_SESSION['cart']??[]; if(!$cart) throw new InvalidArgumentException('Add at least one product before completing the sale.');
                $pdo=db(); $pdo->beginTransaction();
                try {
                    $lines=[]; $subtotal=0.0; $manager=current_user()['role']==='manager'; $override=$manager && !empty($_POST['stock_override']);
                    foreach($cart as $productId=>$qty){
                        $qty=max(0.001,(float)$qty); $stmt=$pdo->prepare('SELECT * FROM products WHERE id=? AND is_active=1 FOR UPDATE'); $stmt->execute([(int)$productId]); $product=$stmt->fetch();
                        if(!$product) throw new InvalidArgumentException('One or more products are no longer active.');
                        if(!$override && (float)$product['current_stock']<$qty) throw new InvalidArgumentException('One or more items no longer have enough stock.');
                        $line=round((float)$product['selling_price']*$qty,2); $subtotal+=$line; $lines[]=[$product,$qty,$line];
                    }
                    $discount=max(0,post_float('discount')); if($discount>$subtotal) $discount=$subtotal;
                    $taxRate=(int)setting_value('vat_enabled',0)?(float)setting_value('vat_rate',0):0; $tax=round(($subtotal-$discount)*$taxRate/100,2); $total=round($subtotal-$discount+$tax,2);
                    $method=post_string('payment_method','cash'); if(!in_array($method,['cash','mobile_money','card','split'],true)) throw new InvalidArgumentException('Invalid payment method.');
                    if($method==='split'){
                        $splits=[['cash',max(0,post_float('cash_amount'))],['mobile_money',max(0,post_float('mobile_amount'))],['card',max(0,post_float('card_amount'))]];
                        $splitTotal=round(array_sum(array_column($splits,1)),2);
                        if(abs($splitTotal-$total)>0.009) throw new InvalidArgumentException('Split payments must equal the total exactly.');
                        $amountPaid=$splitTotal; $change=0;
                    } else {
                        $amountPaid=max(0,post_float('amount_paid')); if($amountPaid<$total) throw new InvalidArgumentException('Amount paid is less than the total.'); $change=round($amountPaid-$total,2);
                    }
                    $invoice=next_invoice_number($pdo);
                    $pdo->prepare('INSERT INTO sales (invoice_number,cashier_id,subtotal,discount_total,tax_total,grand_total,amount_paid,change_due) VALUES (?,?,?,?,?,?,?,?)')->execute([$invoice,current_user()['id'],$subtotal,$discount,$tax,$total,$amountPaid,$change]);
                    $saleId=(int)$pdo->lastInsertId();
                    foreach($lines as [$product,$qty,$line]){
                        $pdo->prepare('INSERT INTO sale_items (sale_id,product_id,name_snapshot,sku_snapshot,qty,unit_price,discount,tax,line_total) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$saleId,$product['id'],$product['name'],$product['sku'],$qty,$product['selling_price'],0,0,$line]);
                        $before=(float)$product['current_stock']; $after=$before-$qty; $pdo->prepare('UPDATE products SET current_stock=? WHERE id=?')->execute([$after,$product['id']]);
                        record_stock_movement($pdo,(int)$product['id'],-$qty,$before,$after,$override?'POS sale (manager stock override)':'Point of sale','sale',$invoice);
                    }
                    if($method==='split'){ foreach($splits as [$m,$amt]) if($amt>0) $pdo->prepare('INSERT INTO payments (sale_id,method,amount,reference) VALUES (?,?,?,?)')->execute([$saleId,$m,$amt,post_string($m.'_reference')]); }
                    else $pdo->prepare('INSERT INTO payments (sale_id,method,amount,reference) VALUES (?,?,?,?)')->execute([$saleId,$method,$amountPaid,post_string('payment_reference')]);
                    audit('create','sale',$saleId); $pdo->commit(); unset($_SESSION['cart']); $_SESSION['last_sale_id']=$saleId; flash('success',$invoice.' completed.'); redirect('receipt',['id'=>$saleId]);
                } catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

            case 'import_upload':
                require_manager();
                if (empty($_FILES['import_file']['tmp_name']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Choose a CSV or XLSX file to upload.');
                $original = basename((string)$_FILES['import_file']['name']);
                $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                if (!in_array($extension, ['csv', 'xlsx'], true)) throw new InvalidArgumentException('Only CSV and XLSX files are accepted.');
                $dir = __DIR__ . '/storage/imports';
                if (!is_dir($dir)) mkdir($dir, 0750, true);
                $stored = $dir . '/' . date('Ymd-His') . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
                if (!move_uploaded_file($_FILES['import_file']['tmp_name'], $stored)) throw new RuntimeException('The upload could not be stored.');
                $parsed = import_rows_from_file($stored, $extension);
                if (!$parsed['headers']) throw new InvalidArgumentException('The file has no header row.');
                $_SESSION['import_preview'] = ['path' => $stored, 'filename' => $original, 'headers' => $parsed['headers'], 'rows' => $parsed['rows']];
                redirect('import', ['step' => 'map']);

            case 'import_commit':
                require_manager();
                $preview=$_SESSION['import_preview']??null; if(!$preview) throw new InvalidArgumentException('Upload a file before importing.');
                $mapping=array_filter((array)($_POST['mapping']??[])); $mode=post_string('duplicate_mode','update');
                $validation=validate_import($preview,$mapping,$mode);
                $_SESSION['import_review']=['mapping'=>$mapping,'duplicate_mode'=>$mode,'validation'=>$validation];
                $_SESSION['import_errors']=$validation['errors'];
                redirect('import',['step'=>'review']);

            case 'import_apply':
                require_manager();
                $preview=$_SESSION['import_preview']??null; $review=$_SESSION['import_review']??null;
                if(!$preview||!$review) throw new InvalidArgumentException('Run validation before importing.');
                if($review['validation']['errors']) throw new InvalidArgumentException('Fix the validation errors before importing. Download the error report for details.');
                $mapping=$review['mapping']; $mode=$review['duplicate_mode']; $pdo=db(); $pdo->beginTransaction();
                try { $created=$updated=$skipped=0; $value=static function(string $field,array $row)use($mapping):string{$i=array_search($field,$mapping,true);return $i===false?'':trim((string)($row[$i]??''));};
                    foreach($preview['rows'] as $rowIndex=>$row){ $sku=$value('sku',$row);$name=$value('name',$row); if($sku===''||$name==='')continue; $existing=$pdo->prepare('SELECT id,current_stock FROM products WHERE sku=? FOR UPDATE');$existing->execute([$sku]);$product=$existing->fetch(); if($product&&$mode==='skip'){ $skipped++;continue; } $cat=category_id($value('category')); $stock=(float)($value('stock')?:0); $data=[$value('barcode')?:null,$name,$cat,(float)($value('purchase_price')?:0),(float)($value('selling_price')?:0),$stock,(float)($value('min_stock')?:0),$value('unit')?:'pcs']; if($product){$before=(float)$product['current_stock'];$pdo->prepare('UPDATE products SET barcode=?,name=?,category_id=?,purchase_price=?,selling_price=?,current_stock=?,minimum_stock=?,unit=? WHERE id=?')->execute([...$data,$product['id']]); if(abs($stock-$before)>0.000001)record_stock_movement($pdo,(int)$product['id'],$stock-$before,$before,$stock,'Legacy stock import','import');$updated++;}else{$pdo->prepare('INSERT INTO products (sku,barcode,name,category_id,purchase_price,selling_price,current_stock,minimum_stock,unit) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$sku,...$data]);$newId=(int)$pdo->lastInsertId();record_stock_movement($pdo,$newId,$stock,0,$stock,'Legacy stock import','import');$created++;} }
                    $pdo->commit(); unset($_SESSION['import_preview'],$_SESSION['import_review']); flash('success',"Import complete: {$created} created, {$updated} updated, {$skipped} skipped, 0 errors."); redirect('import');
                }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

            case 'download_errors':
                require_manager();
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="import-errors.csv"');
                $out = fopen('php://output', 'wb');
                fputcsv($out, ['row', 'error']);
                foreach ((array)($_SESSION['import_errors'] ?? []) as $error) fputcsv($out, $error);
                fclose($out);
                exit;
        }
    }
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    flash('error', $error instanceof InvalidArgumentException ? $error->getMessage() : 'That action could not be completed.');
    redirect($page === 'login' ? 'login' : $page);
}

if ($page === 'login') {
    render_header('Staff sign in');
    ?>
    <div class="auth-page">
        <section class="auth-brand">
            <div class="brand-kicker">TORKS & HYLL / STORE OPERATIONS</div>
            <div class="auth-brand-copy">
                <span class="brand-mark large">T<span>&</span>H</span>
                <h1>Good shops run<br>on good rhythm.</h1>
                <p>Your counter, in control.</p>
            </div>
            <div class="auth-quote">Secure Retail Management System</div>
        </section>
        <section class="auth-form-wrap">
            <div class="auth-form">
                <div class="mobile-brand"><span class="brand-mark">T<span>&</span>H</span> Torks & Hyll</div>
                <span class="eyebrow">STAFF PORTAL</span>
                <h2>Welcome back.</h2>
                <p class="muted">Sign in to keep the shop moving.</p>
                <form method="post" action="<?= e(url('login')) ?>">
                    <input type="hidden" name="action" value="login">
                    <?= csrf_field() ?>
                    <label>Email address<input type="email" name="email" autocomplete="username" placeholder="you@torkshyll.com" required></label>
                    <label>Password<input type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required></label>
                    <button class="button button-gold full" type="submit">Sign in <span>→</span></button>
                </form>
                <p class="form-foot">Need access? Ask your store manager.</p>
            </div>
        </section>
    </div>
    <?php render_footer(); exit;
}

if (!can($page)) {
    http_response_code(403);
    exit('403 — You do not have access to this page.');
}

if ($page === 'dashboard' && ($_GET['data'] ?? '') === '7day' && current_user()['role'] === 'manager') {
    header('Content-Type: application/json; charset=utf-8');
    $labels=[];$values=[];
    for($i=6;$i>=0;$i--){$date=date('Y-m-d',strtotime("-$i day"));$stmt=db()->prepare("SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE status='completed' AND DATE(created_at)=?");$stmt->execute([$date]);$labels[]=date('D d',strtotime($date));$values[]=(float)$stmt->fetchColumn();}
    echo json_encode(['labels'=>$labels,'values'=>$values]); exit;
}

$title = ucfirst($page);
render_header($title);

if ($page === 'dashboard') {
    $isManager=current_user()['role']==='manager';
    $scope=$isManager?'':' AND cashier_id=?'; $scopeArgs=$isManager?[]:[current_user()['id']];
    $todayStmt=db()->prepare("SELECT COUNT(*) count, COALESCE(SUM(grand_total),0) revenue FROM sales WHERE status='completed' AND DATE(created_at)=CURDATE()".$scope);$todayStmt->execute($scopeArgs);$today=$todayStmt->fetch();
    $weekStmt=db()->prepare("SELECT COALESCE(SUM(grand_total),0) revenue FROM sales WHERE status='completed' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)".$scope);$weekStmt->execute($scopeArgs);$week=$weekStmt->fetch();
    $monthStmt=db()->prepare("SELECT COALESCE(SUM(grand_total),0) revenue FROM sales WHERE status='completed' AND created_at>=DATE_FORMAT(NOW(),'%Y-%m-01')".$scope);$monthStmt->execute($scopeArgs);$month=$monthStmt->fetch();
    $low=db()->query('SELECT COUNT(*) FROM products WHERE is_active=1 AND current_stock<=minimum_stock')->fetchColumn();
    if($isManager){$cost=(float)db()->query("SELECT COALESCE(SUM(si.qty*p.purchase_price),0) FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id WHERE s.status='completed' AND DATE(s.created_at)=CURDATE()")->fetchColumn();$profit=(float)$today['revenue']-$cost;}else{$cost=0;$profit=0;}
    if($isManager){$recent=db()->query("SELECT s.*,CONCAT(u.first_name,' ',u.last_name) cashier FROM sales s JOIN users u ON u.id=s.cashier_id WHERE s.status='completed' ORDER BY s.created_at DESC LIMIT 6")->fetchAll();$top=db()->query("SELECT si.name_snapshot name,SUM(si.qty) qty,SUM(si.line_total) total FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='completed' GROUP BY si.name_snapshot ORDER BY qty DESC LIMIT 5")->fetchAll();}
    else{$stmt=db()->prepare("SELECT s.*,CONCAT(u.first_name,' ',u.last_name) cashier FROM sales s JOIN users u ON u.id=s.cashier_id WHERE s.status='completed' AND s.cashier_id=? ORDER BY s.created_at DESC LIMIT 6");$stmt->execute([current_user()['id']]);$recent=$stmt->fetchAll();$stmt=db()->prepare("SELECT si.name_snapshot name,SUM(si.qty) qty,SUM(si.line_total) total FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='completed' AND s.cashier_id=? GROUP BY si.name_snapshot ORDER BY qty DESC LIMIT 5");$stmt->execute([current_user()['id']]);$top=$stmt->fetchAll();}
    ?>
    <div class="page-heading"><div><span class="eyebrow"><?= $isManager?'STORE OVERVIEW':'YOUR SHIFT' ?> / <?= e(strtoupper(current_user()['first_name'])) ?></span><h1><?= $isManager?'Store overview':'Cashier dashboard' ?></h1><p class="muted"><?= $isManager?'Here’s the pulse of Torks & Hyll today.':'Your sales activity and stock alerts.' ?></p></div><a href="<?=e(url('pos'))?>" class="button button-gold">Open point of sale <span>→</span></a></div>
    <div class="metrics">
      <div class="metric metric-gold"><span><?= $isManager?'Today’s revenue':'My revenue today' ?></span><strong><?=e(money($today['revenue']))?></strong><small><?=e((int)$today['count'])?> completed sales</small></div>
      <div class="metric"><span>Last 7 days</span><strong><?=e(money($week['revenue']))?></strong><small><?= $isManager?'Store revenue':'Your revenue' ?></small></div>
      <div class="metric"><span>This month</span><strong><?=e(money($month['revenue']))?></strong><small><?= $isManager?'Store revenue':'Your revenue' ?></small></div>
      <div class="metric metric-alert"><span>Low stock</span><strong><?=e((int)$low)?></strong><small>Items need attention</small></div>
    </div>
    <?php if($isManager): ?><div class="metrics"><div class="metric"><span>Today’s cost</span><strong><?=e(money($cost))?></strong><small>Based on purchase price</small></div><div class="metric"><span>Gross profit</span><strong><?=e(money($profit))?></strong><small>Revenue less product cost</small></div></div><section class="panel chart-panel"><div class="panel-head"><div><span class="eyebrow">PERFORMANCE</span><h2>Revenue trend</h2></div><span class="muted mono">LAST 7 DAYS</span></div><div class="chart-wrap"><canvas id="revenueChart" height="100"></canvas></div></section><?php endif; ?>
    <div class="dashboard-grid"><section class="panel"><div class="panel-head"><div><span class="eyebrow">ACTIVITY</span><h2><?= $isManager?'Recent transactions':'My recent sales' ?></h2></div><a href="<?=e(url('sales'))?>" class="text-link">View all →</a></div><?php if(!$recent):?><div class="empty">No transactions recorded yet.</div><?php endif;?><?php foreach($recent as $sale):?><a class="transaction" href="<?=e(url('receipt',['id'=>$sale['id']]))?>"><span class="transaction-icon">↗</span><span><strong><?=e($sale['invoice_number'])?></strong><small><?=e($sale['cashier'])?> · <?=e(date('d M, H:i',strtotime($sale['created_at'])))?></small></span><b><?=e(money($sale['grand_total']))?></b></a><?php endforeach;?></section>
    <section class="panel"><div class="panel-head"><div><span class="eyebrow">TOP PRODUCTS</span><h2><?= $isManager?'Moving fastest':'Your top products' ?></h2></div><span class="muted mono">UNITS SOLD</span></div><?php if(!$top):?><div class="empty">Sales activity will appear here.</div><?php endif;?><?php foreach($top as $index=>$item):?><div class="rank-row"><span class="rank"><?=e(str_pad((string)($index+1),2,'0',STR_PAD_LEFT))?></span><span><strong><?=e($item['name'])?></strong><small><?=e(money($item['total']))?> revenue</small></span><b><?=e(number_format((float)$item['qty'],0))?></b></div><?php endforeach;?></section></div>
    <?php if($isManager): ?><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script><script>fetch('<?=e(url('dashboard',['data'=>'7day']))?>',{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(d=>{new Chart(document.getElementById('revenueChart'),{type:'line',data:{labels:d.labels,datasets:[{label:'Revenue',data:d.values,tension:.35}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}})});</script><?php endif; ?>
    <?php
} elseif ($page === 'inventory') {
    $q = trim((string)($_GET['q'] ?? ''));
    $edit = (int)($_GET['edit'] ?? 0);
    $sql = 'SELECT p.*, c.name category FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.is_active=1';
    $args = [];
    if ($q !== '') { $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)'; $args = ["%$q%", "%$q%", "%$q%"]; }
    $sql .= ' ORDER BY p.name LIMIT 200';
    $stmt = db()->prepare($sql); $stmt->execute($args); $products = $stmt->fetchAll();
    $categories = db()->query('SELECT * FROM categories ORDER BY name')->fetchAll();
    $editing = null;
    if ($edit) { $s = db()->prepare('SELECT p.*, c.name category FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=?'); $s->execute([$edit]); $editing = $s->fetch(); }
    ?>
    <div class="page-heading"><div><span class="eyebrow">CATALOGUE / <?= e(count($products)) ?> ACTIVE ITEMS</span><h1>Inventories</h1><p class="muted"><?= current_user()['role'] === 'manager' ? 'Keep every shelf accounted for.' : 'Browse current prices and stock levels.' ?></p></div><?php if (current_user()['role'] === 'manager'): ?><a class="button button-gold" href="<?= e(url('inventory', ['edit' => 0, 'new' => 1])) ?>">＋ Add product</a><?php endif; ?></div>
    <?php if (current_user()['role'] === 'manager' && ($edit || isset($_GET['new']))): ?><section class="panel form-panel"><div class="panel-head"><div><span class="eyebrow"><?= $editing ? 'EDIT ITEM' : 'NEW ITEM' ?></span><h2><?= $editing ? 'Update product' : 'Add a product' ?></h2></div><a class="text-link" href="<?= e(url('inventory')) ?>">Close</a></div><form class="form-grid" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="product_save"><input type="hidden" name="id" value="<?= e($editing['id'] ?? 0) ?>"><label>Product name<input name="name" required value="<?= e($editing['name'] ?? '') ?>"></label><label>SKU<input name="sku" required value="<?= e($editing['sku'] ?? '') ?>"></label><label>Barcode<input name="barcode" value="<?= e($editing['barcode'] ?? '') ?>"></label><label>Category<select name="category"><option value="">Uncategorised</option><?php foreach ($categories as $cat): ?><option<?= selected($editing['category'] ?? '', $cat['name']) ?>><?= e($cat['name']) ?></option><?php endforeach; ?></select></label><label>Purchase price<input type="number" step="0.01" min="0" name="purchase_price" value="<?= e($editing['purchase_price'] ?? '0') ?>"></label><label>Selling price<input type="number" step="0.01" min="0" name="selling_price" value="<?= e($editing['selling_price'] ?? '0') ?>" required></label><label>Current stock<input type="number" step="0.001" min="0" name="stock" value="<?= e($editing['current_stock'] ?? '0') ?>"></label><label>Minimum stock<input type="number" step="0.001" min="0" name="min_stock" value="<?= e($editing['minimum_stock'] ?? '0') ?>"></label><label>Unit<input name="unit" value="<?= e($editing['unit'] ?? 'pcs') ?>"></label><label class="span-2">Description<textarea name="description"><?= e($editing['description'] ?? '') ?></textarea></label><div class="span-2 form-actions"><button class="button button-gold">Save product</button></div></form></section><?php endif; ?>
    <?php $isManager = current_user()['role'] === 'manager'; ?>
    <section class="panel">
        <div class="toolbar">
            <form class="search"><input name="q" value="<?= e($q) ?>" placeholder="Search name, SKU or barcode"><button>Search</button></form>
            <span class="muted"><?= e(count($products)) ?> results</span>
        </div>
        <div class="table-wrap">
            <table><thead><tr><th>Product</th><th>SKU / Barcode</th><th>Category</th><th>Price</th><th>Stock</th><?php if ($isManager): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($products as $product): ?>
                <tr>
                    <td><strong><?= e($product['name']) ?></strong><small><?= e($product['unit']) ?></small></td>
                    <td class="mono"><?= e($product['sku']) ?><small><?= e($product['barcode'] ?: 'No barcode') ?></small></td>
                    <td><?= e($product['category'] ?: 'Uncategorised') ?></td>
                    <td><?= e(money($product['selling_price'])) ?></td>
                    <td><span class="stock <?= (float)$product['current_stock'] <= (float)$product['minimum_stock'] ? 'stock-low' : '' ?>"><?= e(number_format((float)$product['current_stock'], 0)) ?></span><small>min <?= e(number_format((float)$product['minimum_stock'], 0)) ?></small></td>
                    <?php if ($isManager): ?><td class="row-actions"><a href="<?= e(url('inventory', ['edit' => $product['id']])) ?>">Edit</a><details class="adjust-details"><summary>Stock</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="stock_adjust"><input type="hidden" name="id" value="<?= e($product['id']) ?>"><input type="number" step="0.001" name="qty" placeholder="+ / - qty" required><input name="reason" placeholder="Reason" required><button>Apply</button></form></details><form method="post" class="inline-form" data-confirm="Remove this product from active inventory?"><?= csrf_field() ?><input type="hidden" name="action" value="product_delete"><input type="hidden" name="id" value="<?= e($product['id']) ?>"><button class="danger-link">Remove</button></form></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php if (!$products): ?><div class="empty">No products match your search.</div><?php endif; ?>
        </div>
    </section>
    <?php
} elseif ($page === 'pos') {
    $q = trim((string)($_GET['q'] ?? ''));
    $results = [];
    if ($q !== '') { $s = db()->prepare('SELECT * FROM products WHERE is_active=1 AND (name LIKE ? OR sku LIKE ? OR barcode LIKE ?) ORDER BY name LIMIT 12'); $s->execute(["%$q%", "%$q%", "%$q%"]); $results = $s->fetchAll(); }
    $cart = $_SESSION['cart'] ?? [];
    $cartRows = [];
    $subtotal = 0;
    foreach ($cart as $id => $qty) { $s = db()->prepare('SELECT * FROM products WHERE id=? AND is_active=1'); $s->execute([(int)$id]); if ($p=$s->fetch()) { $line = $p['selling_price'] * $qty; $subtotal += $line; $cartRows[] = [$p, $qty, $line]; } }
    ?>
    <div class="page-heading compact"><div><span class="eyebrow">COUNTER / <?= e(date('d M Y')) ?></span><h1>Point of sale</h1></div><span class="live-pill"><i></i> Ready for checkout</span></div>
    <div class="pos-grid"><section class="pos-products"><div class="panel pos-search"><form class="search big-search"><span>⌕</span><input autofocus name="q" value="<?= e($q) ?>" placeholder="Scan barcode or search product name"><button>Find</button></form><?php if ($q && !$results): ?><div class="empty">No products found. Try a SKU or barcode.</div><?php endif; ?><?php foreach ($results as $p): ?><form class="product-result" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cart_add"><input type="hidden" name="product_id" value="<?= e($p['id']) ?>"><div><strong><?= e($p['name']) ?></strong><small><?= e($p['sku']) ?> · <?= e(number_format((float)$p['current_stock'], 0)) ?> in stock</small></div><b><?= e(money($p['selling_price'])) ?></b><button class="add-button">Add</button></form><?php endforeach; ?></div></section><section class="panel cart-panel"><div class="panel-head"><div><span class="eyebrow">CURRENT ORDER</span><h2>Your cart <span class="count-badge"><?= e(count($cartRows)) ?></span></h2></div><?php if ($cartRows): ?><form method="post" class="inline-form" data-confirm="Clear this order?"><?= csrf_field() ?><input type="hidden" name="action" value="cart_clear"><button class="text-link">Clear all</button></form><?php endif; ?></div><?php if (!$cartRows): ?><div class="cart-empty"><span>＋</span><strong>Your cart is empty</strong><p>Search or scan a product to begin.</p></div><?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cart_update"><?php foreach ($cartRows as [$p,$qty,$line]): ?><div class="cart-line"><div><strong><?= e($p['name']) ?></strong><small><?= e($p['sku']) ?> · <?= e(money($p['selling_price'])) ?></small></div><input class="qty-input" type="number" min="0" step="1" name="qty[<?= e($p['id']) ?>]" value="<?= e($qty) ?>"><b><?= e(money($line)) ?></b></div><?php endforeach; ?><button class="button button-ghost full">Update quantities</button></form><div class="totals"><div><span>Subtotal</span><b><?= e(money($subtotal)) ?></b></div><form method="post" class="checkout-form"><?= csrf_field() ?><input type="hidden" name="action" value="sale_complete"><label>Discount<input type="number" min="0" step="0.01" name="discount" value="0"></label><div class="total-line"><span>Total</span><strong id="pos-total" data-subtotal="<?=e(number_format((float)$subtotal,2,'.',''))?>" data-tax-rate="<?=e(number_format((int)setting_value('vat_enabled',0)?(float)setting_value('vat_rate',0):0,2,'.',''))?>"><?= e(money($subtotal)) ?></strong></div><label>Payment method<select name="payment_method" id="payment-method"><option value="cash">Cash</option><option value="mobile_money">Mobile Money</option><option value="card">Card</option><option value="split">Split payment</option></select></label><label>Amount paid<input type="number" min="0" step="0.01" name="amount_paid" required value="<?= e($subtotal) ?>"></label><?php if(current_user()['role']==='manager' && (int)setting_value('stock_override_enabled',0)===1): ?><label class="check-label pos-override"><input type="checkbox" name="stock_override" value="1"> Allow stock to go below zero</label><?php endif; ?><div class="split-fields"><label>Cash<input type="number" step="0.01" name="cash_amount"></label><label>Mobile money<input type="number" step="0.01" name="mobile_amount"></label><label>Card<input type="number" step="0.01" name="card_amount"></label></div><button class="button button-gold full">Complete sale <span>→</span></button></form></div><?php endif; ?></section></div>
    <?php
} elseif ($page === 'sales') {
    $from = (string)($_GET['from'] ?? date('Y-m-01')); $to = (string)($_GET['to'] ?? date('Y-m-d')); $cashier = (int)($_GET['cashier'] ?? 0); $method = (string)($_GET['method'] ?? '');
    $sql = 'SELECT s.*, CONCAT(u.first_name," ",u.last_name) cashier, GROUP_CONCAT(DISTINCT p.method) methods FROM sales s JOIN users u ON u.id=s.cashier_id LEFT JOIN payments p ON p.sale_id=s.id WHERE DATE(s.created_at) BETWEEN ? AND ?'; $args=[$from,$to];
    if (current_user()['role'] !== 'manager') {$sql.=' AND s.cashier_id=?';$args[]=current_user()['id'];}
    if ($cashier) {$sql.=' AND s.cashier_id=?';$args[]=$cashier;} if ($method) {$sql.=' AND p.method=?';$args[]=$method;} $sql.=' GROUP BY s.id ORDER BY s.created_at DESC LIMIT 250'; $s=db()->prepare($sql);$s->execute($args);$sales=$s->fetchAll();$cashiers=db()->query("SELECT id, first_name,last_name FROM users WHERE role='cashier' ORDER BY first_name")->fetchAll();
    ?>
    <div class="page-heading"><div><span class="eyebrow">TRANSACTIONS / HISTORY</span><h1>Sales made</h1><p class="muted"><?= current_user()['role']==='manager' ? 'A complete view of every checkout.' : 'Your recorded checkouts.' ?></p></div></div><section class="panel"><form class="filters"><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><?php if(current_user()['role']==='manager'): ?><label>Cashier<select name="cashier"><option value="0">All cashiers</option><?php foreach($cashiers as $c): ?><option value="<?= e($c['id']) ?>"<?= selected($cashier,$c['id']) ?>><?= e($c['first_name'].' '.$c['last_name']) ?></option><?php endforeach; ?></select></label><label>Payment<select name="method"><option value="">All methods</option><option value="cash"<?= selected($method,'cash') ?>>Cash</option><option value="mobile_money"<?= selected($method,'mobile_money') ?>>Mobile Money</option><option value="card"<?= selected($method,'card') ?>>Card</option></select></label><?php endif; ?><button class="button button-dark">Filter</button></form><div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Date / cashier</th><th>Payment</th><th>Total</th><th></th></tr></thead><tbody><?php foreach($sales as $sale): ?><tr><td class="mono"><?= e($sale['invoice_number']) ?></td><td><strong><?= e(date('d M Y, H:i',strtotime($sale['created_at']))) ?></strong><small><?= e($sale['cashier']) ?></small></td><td><?= e(ucwords(str_replace('_',' ', $sale['methods'] ?: '—'))) ?></td><td><strong><?= e(money($sale['grand_total'])) ?></strong></td><td><a class="text-link" href="<?= e(url('receipt',['id'=>$sale['id']])) ?>">Receipt →</a></td></tr><?php endforeach; ?></tbody></table><?php if(!$sales): ?><div class="empty">No sales found for these filters.</div><?php endif; ?></div></section>
    <?php
} elseif ($page === 'receipt') {
    $id=(int)($_GET['id']??0);$s=current_user()['role']==='manager'?db()->prepare('SELECT s.*, CONCAT(u.first_name," ",u.last_name) cashier FROM sales s JOIN users u ON u.id=s.cashier_id WHERE s.id=?'):db()->prepare('SELECT s.*, CONCAT(u.first_name," ",u.last_name) cashier FROM sales s JOIN users u ON u.id=s.cashier_id WHERE s.id=? AND s.cashier_id=?'); if(current_user()['role']==='manager')$s->execute([$id]);else$s->execute([$id,current_user()['id']]);$sale=$s->fetch();if(!$sale){http_response_code(404);exit('Receipt not found.');}$i=db()->prepare('SELECT * FROM sale_items WHERE sale_id=?');$i->execute([$id]);$items=$i->fetchAll();$p=db()->prepare('SELECT * FROM payments WHERE sale_id=?');$p->execute([$id]);$payments=$p->fetchAll();
    ?><div class="receipt-actions"><a class="text-link" href="<?= e(url('sales')) ?>">← Back to sales</a><button class="button button-gold" onclick="window.print()">Print receipt</button></div><section class="receipt"><div class="receipt-head"><?php if (setting_value('logo_path')): ?><img class="receipt-logo" src="<?= e(setting_value('logo_path')) ?>" alt="Logo"><?php else: ?><span class="brand-mark">T<span>&</span>H</span><?php endif; ?><h1><?= e(setting_value('business_name','Torks & Hyll')) ?></h1><p><?= e(setting_value('address')) ?><br><?= e(setting_value('phone')) ?></p><?php if(setting_value('receipt_header')):?><p><?=e(setting_value('receipt_header'))?></p><?php endif;?></div><div class="receipt-meta"><span><?= e($sale['invoice_number']) ?></span><span><?= e(date('d M Y, H:i',strtotime($sale['created_at']))) ?></span><span>Cashier: <?= e($sale['cashier']) ?></span></div><?php foreach($items as $item):?><div class="receipt-item"><span><?=e($item['name_snapshot'])?><small><?=e($item['qty'])?> × <?=e(money($item['unit_price']))?></small></span><b><?=e(money($item['line_total']))?></b></div><?php endforeach;?><div class="receipt-total"><div><span>Subtotal</span><b><?=e(money($sale['subtotal']))?></b></div><div><span>Discount</span><b><?=e(money($sale['discount_total']))?></b></div><div><span>Tax</span><b><?=e(money($sale['tax_total']))?></b></div><div class="grand"><span>Total</span><b><?=e(money($sale['grand_total']))?></b></div><div><span>Paid</span><b><?=e(money($sale['amount_paid']))?></b></div><div><span>Change</span><b><?=e(money($sale['change_due']))?></b></div></div><div class="receipt-foot"><?php foreach($payments as $payment):?><p><?=e(ucwords(str_replace('_',' ',$payment['method'])))?> · <?=e(money($payment['amount']))?></p><?php endforeach;?><?=e(setting_value('receipt_footer','Thank you for shopping with us.'))?></div></section>
    <?php
} elseif ($page === 'users') {
    require_manager();$users=db()->query('SELECT * FROM users ORDER BY is_active DESC, first_name')->fetchAll();$edit=(int)($_GET['edit']??0);$editing=null;if($edit){$s=db()->prepare('SELECT * FROM users WHERE id=?');$s->execute([$edit]);$editing=$s->fetch();}
    ?><div class="page-heading"><div><span class="eyebrow">ACCESS CONTROL</span><h1>Team access</h1><p class="muted">Keep the right people behind the counter.</p></div><a class="button button-gold" href="<?=e(url('users',['new'=>1]))?>">＋ Add team member</a></div><?php if($editing||isset($_GET['new'])):?><section class="panel form-panel"><div class="panel-head"><div><span class="eyebrow"><?=$editing?'EDIT USER':'NEW USER'?></span><h2><?=$editing?'Update team member':'Add team member'?></h2></div><a class="text-link" href="<?=e(url('users'))?>">Close</a></div><form class="form-grid" method="post"><?=csrf_field()?><input type="hidden" name="action" value="user_save"><input type="hidden" name="id" value="<?=e($editing['id']??0)?>"><label>First name<input name="first_name" required value="<?=e($editing['first_name']??'')?>"></label><label>Last name<input name="last_name" required value="<?=e($editing['last_name']??'')?>"></label><label>Email<input type="email" name="email" required value="<?=e($editing['email']??'')?>"></label><label>Phone<input name="phone" value="<?=e($editing['phone']??'')?>"></label><label>Role<select name="role"><option value="cashier"<?=selected($editing['role']??'','cashier')?>>Cashier</option><option value="manager"<?=selected($editing['role']??'','manager')?>>Manager</option></select></label><label>Password<input type="password" name="password" <?= $editing?'placeholder="Leave blank to keep current"':'required'?>></label><div class="span-2 form-actions"><button class="button button-gold">Save user</button></div></form></section><?php endif;?><section class="panel"><div class="table-wrap"><table><thead><tr><th>Team member</th><th>Employee ID</th><th>Role</th><th>Last sign in</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($users as $u):?><tr><td><strong><?=e($u['first_name'].' '.$u['last_name'])?></strong><small><?=e($u['email'])?></small></td><td class="mono"><?=e($u['employee_id'])?></td><td><span class="role-pill"><?=e(strtoupper($u['role']))?></span></td><td><?=e($u['last_login_at']?date('d M Y, H:i',strtotime($u['last_login_at'])):'Never')?></td><td><span class="status-label <?=$u['is_active']?'is-active':'is-off'?>"><?=$u['is_active']?'Active':'Inactive'?></span></td><td class="row-actions"><a href="<?=e(url('users',['edit'=>$u['id']]))?>">Edit</a><form class="inline-form" method="post" data-confirm="Change this user's access?"><?=csrf_field()?><input type="hidden" name="action" value="user_toggle"><input type="hidden" name="id" value="<?=e($u['id'])?>"><button class="text-link"><?=$u['is_active']?'Deactivate':'Activate'?></button></form></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php
} elseif ($page === 'categories') {
    require_manager(); $categories=db()->query('SELECT c.*,COUNT(p.id) product_count FROM categories c LEFT JOIN products p ON p.category_id=c.id AND p.is_active=1 GROUP BY c.id ORDER BY c.name')->fetchAll(); $edit=(int)($_GET['edit']??0); $editing=null; if($edit){$q=db()->prepare('SELECT * FROM categories WHERE id=?');$q->execute([$edit]);$editing=$q->fetch();}
    ?>
    <div class="page-heading"><div><span class="eyebrow">CATALOGUE / ORGANISATION</span><h1>Categories</h1><p class="muted">Keep products grouped for faster stock management.</p></div><a class="button button-gold" href="<?=e(url('categories',['new'=>1]))?>">＋ Add category</a></div>
    <?php if($editing||isset($_GET['new'])): ?><section class="panel form-panel"><div class="panel-head"><div><span class="eyebrow"><?= $editing?'EDIT CATEGORY':'NEW CATEGORY' ?></span><h2><?= $editing?'Update category':'Add a category' ?></h2></div><a class="text-link" href="<?=e(url('categories'))?>">Close</a></div><form class="form-grid" method="post"><?=csrf_field()?><input type="hidden" name="action" value="category_save"><input type="hidden" name="id" value="<?=e($editing['id']??0)?>"><label>Category name<input name="name" required value="<?=e($editing['name']??'')?>"></label><div class="form-actions"><button class="button button-gold">Save category</button></div></form></section><?php endif; ?>
    <section class="panel"><div class="table-wrap"><table><thead><tr><th>Category</th><th>Active products</th><th></th></tr></thead><tbody><?php foreach($categories as $cat): ?><tr><td><strong><?=e($cat['name'])?></strong></td><td><?=e($cat['product_count'])?></td><td class="row-actions"><a href="<?=e(url('categories',['edit'=>$cat['id']]))?>">Edit</a><form class="inline-form" method="post" data-confirm="Remove this category? Products will become uncategorised."><?=csrf_field()?><input type="hidden" name="action" value="category_delete"><input type="hidden" name="id" value="<?=e($cat['id'])?>"><button class="danger-link">Remove</button></form></td></tr><?php endforeach; ?></tbody></table><?php if(!$categories):?><div class="empty">No categories yet.</div><?php endif;?></div></section>
    <?php
} elseif ($page === 'settings') {
    require_manager();$st=app_settings();
    ?><div class="page-heading"><div><span class="eyebrow">MANAGER / CONFIGURATION</span><h1>Settings</h1><p class="muted">Your shop’s identity and receipt preferences.</p></div></div><section class="panel form-panel"><form class="form-grid" method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="settings_save"><div class="span-2 section-title">Business profile</div><label>Business name<input name="business_name" value="<?=e($st['business_name']??'Torks & Hyll')?>" required></label><label>Phone<input name="phone" value="<?=e($st['phone']??'')?>"></label><label class="span-2">Address<input name="address" value="<?=e($st['address']??'')?>"></label><label>Email<input type="email" name="email" value="<?=e($st['email']??'')?>"></label><label>TIN<input name="tin" value="<?=e($st['tin']??'')?>"></label><div class="span-2 section-title">Tax & receipt</div><label class="check-label"><input type="checkbox" name="vat_enabled"<?=checked((bool)($st['vat_enabled']??false))?>> Enable VAT on checkout</label><label class="check-label"><input type="checkbox" name="stock_override_enabled"<?=checked((bool)($st['stock_override_enabled']??false))?>> Allow manager stock override at POS</label><label>VAT rate (%)<input type="number" step="0.01" min="0" name="vat_rate" value="<?=e($st['vat_rate']??0)?>"></label><label class="span-2">Receipt header<input name="receipt_header" value="<?=e($st['receipt_header']??'')?>"></label><label class="span-2">Receipt footer<textarea name="receipt_footer"><?=e($st['receipt_footer']??'Thank you for shopping with us.')?></textarea></label><label>Logo<input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp"></label><label>Currency<input name="currency" value="<?=e($st['currency']??'GHS')?>"></label><label>Timezone<input name="timezone" value="<?=e($st['timezone']??'Africa/Accra')?>"></label><div class="span-2 form-actions"><button class="button button-gold">Save settings</button></div></form></section>
    <?php
} elseif ($page === 'import') {
    require_manager();$preview=$_SESSION['import_preview']??null;$errors=$_SESSION['import_errors']??[];$step=(string)($_GET['step']??'upload');
    ?><div class="page-heading"><div><span class="eyebrow">MANAGER / LEGACY DATA</span><h1>Import data</h1><p class="muted">Bring old stock into Torks & Hyll without losing control.</p></div><a class="button button-ghost" href="templates/products_import.csv" download>Download template</a></div><section class="import-steps"><span class="<?=$step==='upload'?'current':''?>">01 Upload</span><span class="<?=$step==='map'?'current':''?>">02 Map columns</span><span>03 Review & import</span></section><?php if($preview&&$step==='review'): $review=$_SESSION['import_review']??null; $validation=$review['validation']??['errors'=>[],'valid'=>[]]; ?><section class="panel"><div class="panel-head"><div><span class="eyebrow">DRY RUN COMPLETE</span><h2>Review import</h2><p class="muted"><?=e(count($validation['valid']))?> valid rows · <?=e(count($validation['errors']))?> errors</p></div></div><div class="import-summary"><div><strong><?=e(count($validation['valid']))?></strong><span>Valid</span></div><div><strong><?=e(count($validation['errors']))?></strong><span>Errors</span></div></div><?php if($validation['errors']):?><div class="import-errors"><h3>Fix these rows before importing</h3><?php foreach(array_slice($validation['errors'],0,20) as $er):?><p>Row <?=e($er[0])?> — <?=e($er[1])?></p><?php endforeach;?></div><div class="form-actions import-review-actions"><a class="button button-ghost" href="<?=e(url('import',['action'=>'download_errors']))?>">Download error CSV</a><a class="button button-dark" href="<?=e(url('import',['step'=>'map']))?>">Back to mapping</a></div><?php else: ?><div class="form-actions import-review-actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="import_apply"><button class="button button-gold">Confirm & import <?=e(count($validation['valid']))?> rows</button></form><a class="button button-ghost" href="<?=e(url('import',['step'=>'map']))?>">Back</a></div><?php endif; ?></section><?php elseif($preview&&$step==='map'):?><section class="panel"><div class="panel-head"><div><span class="eyebrow">FILE READY</span><h2><?=e($preview['filename'])?></h2><p class="muted"><?=e(count($preview['rows']))?> rows · previewing first 20</p></div></div><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="import_commit"><div class="mapping-grid"><?php foreach($preview['headers'] as $index=>$header):?><label><?=e($header)?><select name="mapping[<?=e($index)?>]"><option value="">Ignore this column</option><?php foreach(['sku'=>'SKU','barcode'=>'Barcode','name'=>'Name','category'=>'Category','purchase_price'=>'Purchase price','selling_price'=>'Selling price','stock'=>'Stock','min_stock'=>'Minimum stock','unit'=>'Unit'] as $field=>$label):?><option value="<?=e($field)?>"<?=selected(import_default_field($header),$field)?>><?=e($label)?></option><?php endforeach;?></select></label><?php endforeach;?></div><div class="import-choice"><label>Existing SKU behavior<select name="duplicate_mode"><option value="update">Update existing products</option><option value="skip">Skip existing products</option></select></label><button class="button button-gold">Validate & review</button></div><div class="table-wrap preview-table"><table><thead><tr><?php foreach($preview['headers'] as $header):?><th><?=e($header)?></th><?php endforeach;?></tr></thead><tbody><?php foreach(array_slice($preview['rows'],0,20) as $row):?><tr><?php foreach($preview['headers'] as $i=>$header):?><td><?=e($row[$i]??'')?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div></form></section><?php else:?><section class="import-drop panel"><div class="upload-icon">⇩</div><h2>Upload your old stock file</h2><p class="muted">CSV or XLSX · first row must be headers</p><form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="import_upload"><input type="file" name="import_file" accept=".csv,.xlsx" required><button class="button button-gold">Upload & preview <span>→</span></button></form></section><section class="panel import-note"><span class="eyebrow">YOUR SUPPLIED FILE</span><h2>products.csv is ready to import</h2><p class="muted">The owner’s file is preserved in the project and matches the importer fields. It has no categories, so those products will appear as Uncategorised until you assign them.</p><?php if($errors):?><a class="text-link" href="<?=e(url('import',['action'=>'download_errors']))?>">Download last error report →</a><?php endif;?></section><?php endif;?>
    <?php
}
render_footer();