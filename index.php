<?php

$db = new mysqli('localhost', 'root', '', 'am');
$db->set_charset('utf8');

$args = explode('/', key($_GET));
$method = $args[0];
$param = $args[1];
if(empty($method)) {
    echo 'Empty request';
    exit;
}
header('Content-type: applicaton/json', true, 200);
//allow cors
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, PUT');
header("Access-Control-Allow-Headers: *");

function auth($conn) {
    $header = apache_request_headers()['Authorization'];
    if(empty($header)) {
        http_response_code(401);
        echo json_encode(['message' => 'Необходима аутентификация']);
        exit;
    } else {
        $token = explode(' ', $header)[1];
        $result = $conn->query("SELECT * FROM `user` WHERE `accessToken`='$token'");
        if($result->num_rows == 0) {
            http_response_code(403);
            echo json_encode(['message' => 'Неверный токен']);
            exit;
        }  
        return $token;
    }
}
function paginate() {
    $page = 1;
    $pageSize = 10;
    if(!empty($_GET['page'])) $page = $_GET['page'];
    if(!empty($_GET['pageSize'])) $pageSize = $_GET['pageSize'];
    $pageOffset = $pageSize * ($page - 1);
    return [$pageOffset, $pageSize];
}
function getCategory($conn, $name) {
    auth($conn);
    [$pageOffset, $pageSize] = paginate();
    $result = $conn->query("SELECT * FROM `$name` LIMIT $pageOffset, $pageSize");
    if($result->num_rows > 0) {
        $rows = array();
        while($row = $result->fetch_assoc()) array_push($rows, $row);
        echo json_encode($rows);
    }
}
function validate($errors, $conn, $motherboardId, $powerSupplyId, $processorId, $ramMemoryId, $ramMemoryAmount, $storageDevices, $graphicCardId, $graphicCardAmount) {
    $mb = null;
    $psu = null;
    $cpu = null;
    $ram = null;
    $gpu = null;
    if($motherboardId) $mb = $conn->query("SELECT * FROM `motherboard` WHERE `id`='$motherboardId'")->fetch_assoc();
    if($motherboardId) $psu = $conn->query("SELECT * FROM `powerSupply` WHERE `id`='$powerSupplyId'")->fetch_assoc();
    if($motherboardId) $cpu = $conn->query("SELECT * FROM `processor` WHERE `id`='$processorId'")->fetch_assoc();
    if($motherboardId) $ram = $conn->query("SELECT * FROM `ramMemory` WHERE `id`='$ramMemoryId'")->fetch_assoc();
    if($motherboardId) $gpu = $conn->query("SELECT * FROM `graphicCard` WHERE `id`='$graphicCardId'")->fetch_assoc();
    if($mb && $ram && $mb['ramMemoryTypeId']!=$ram['ramMemoryTypeId']) $errors['incompatRam'] = 'Материнская плата не поддерживает эту оперативную память';
    if($mb && $ramMemoryAmount && $mb['ramMemorySlots'] < $ramMemoryAmount) $errors['incompatRamAmount'] = 'Материнская плата не поддерживает такое количество оперативной памяти';
    if($mb && $cpu) {
        if($mb['socketTypeId']!=$cpu['socketTypeId']) $errors['incompatCPUsocket'] = 'Сокеты процесоора и материнской платы отличаются';
        if($mb['maxTdp']<$cpu['tdp']) $errors['incompatCPUtdp'] = 'TDP процесоора и материнской платы несовместимы';
    }
    if($gpu && $psu && $gpu['minimumPowerSupply'] > $psu['potency']) $errors['incompatPSU'] = 'Блок питания не обладает длстаточной мощностью';
    if($gpu && $graphicCardAmount && $gpu['supportMultiGpu'] == 0 && $graphicCardAmount > 1) $errors['incompatMultiGPU'] = 'Видеокарта не поддерживает технологию SLI/CROSSFIRE';
    if($mb && $graphicCardAmount && $mb['pciSlots'] < $graphicCardAmount) $errors['incompatGPUamount'] = 'Недостаточно слотов pci для видеокарт';
    $sata = 0;
    $m2 = 0;
    foreach ($storageDevices as $device) {
        if(empty($device['storageDeviceId'])) $errors['storageDeviceId'] = 'Пустой storageDeviceId';
        else {
            $id = $device['storageDeviceId'];
            $data = $conn->query("SELECT * FROM `storageDevice` WHERE `id`='$id'")->fetch_assoc();
            if($data['storageDeviceInterface'] == 'sata') $sata++;
            if($data['storageDeviceInterface'] == 'm2') $m2++;
        }
        if(empty($device['amount'])) $errors['storageDeviceAmount'] = 'Пустой amount';
    }
    if($mb && $mb['sataSlots'] < $sata) $errors['incompatSataAmount'] = 'Недостаточно слотов sata для устройств хранения';
    if($mb && $mb['m2Slots'] < $m2) $errors['incompatM2Amount'] = 'Недостаточно слотов m2 для устройств хранения';
    return $errors;
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        switch($method) {
            case 'motherboards': //get all motherboards
                getCategory($db, 'motherboard');
                break;
            case 'processors': //get all processors
                getCategory($db, 'processor');
                break;
            case 'ram-memories': //get all ram-memories
                getCategory($db, 'ramMemory');
                break;
            case 'storage-devices': //get all storage-devices
                getCategory($db, 'storageDevice');
                break;
            case 'graphic-cards': //get all graphic-cards
                getCategory($db, 'graphicCard');
                break;
            case 'power-supplies': //get all power-supplies
                getCategory($db, 'powerSupply');
                break;
            case 'machines': //get all machines
                getCategory($db, 'machine');
                break;
            case 'brands': //get all brands
                getCategory($db, 'brand');
                break;
            case 'search': //search by product name in specified category
                auth($db);
                [$pageOffset, $pageSize] = paginate();
                $request = $_GET['q'];
                $category = '';
                switch($param) {
                    case 'motherboards': 
                        $category = 'motherboard';
                        break;
                    case 'processors': 
                        $category = 'processor';
                        break;
                    case 'ram-memories': 
                        $category = 'ramMemory';
                        break;
                    case 'storage-devices': 
                        $category = 'storageDevice';
                        break;
                    case 'graphic-cards': 
                        $category = 'graphicCard';
                        break;
                    case 'power-supplies': 
                        $category = 'powerSupply';
                        break;
                    case 'machines': 
                        $category = 'machine';
                        break;
                    case 'brands': 
                        $category = 'brand';
                        break;
                }
                $result = $db->query("SELECT * FROM `$category` WHERE `name` LIKE '%$request%' LIMIT $pageOffset, $pageSize");
                if($result->num_rows > 0) {
                    $rows = array();
                    while($row = $result->fetch_assoc()) array_push($rows, $row);
                    echo json_encode($rows);
                }
                break;
            case 'images': //Get image by name
                $file = null;
                $ext = explode('.', $param)[1];
                if(empty($ext)) {
                    $ext = 'png';
                    $file = file_get_contents('images/' . $param . '.png');
                } else $file = file_get_contents('images/' . $param);
                if($file) {
                    header('Content-type: image/' . $ext, true, 200);
                    echo $file;
                } else {
                    http_response_code(404);
                    echo json_encode(['message' => 'Изображение не найдено']);
                }
                break;
        }
        break;

    case 'POST':
        switch($method) {
            case 'login':
                $input = json_decode(file_get_contents('php://input'), true);
                $username = $input['username'];
                $password = $input['password'];
                if(empty($username) || empty($password)) {
                    http_response_code(400);
                    echo json_encode(['message' => 'Неверные учетные данные']);
                } else {
                    $result = $db->query("SELECT `accessToken` FROM `user` WHERE `username`='$username' AND `password`='$password'");
                    if($result->num_rows > 0) {
                        if(empty($result->fetch_assoc()['accessToken'])) {
                            $token = md5(rand(1, 100));
                            $db->query("UPDATE `user` SET `accessToken` = '$token' WHERE `username`='$username' AND `password`='$password'");
                            echo json_encode(['token' => $token]);
                        } else {
                            http_response_code(403);
                            echo json_encode(['message' => "Пользователь уже аутентифицирован"]);
                        }
                    } else {
                        http_response_code(400);
                        echo json_encode(['message' => 'Неверные учетные данные']);
                    }
                }
                break;
            case 'machines': //Create new machine
                auth($db);
                $input = json_decode(file_get_contents('php://input'), true);
                $name = $input['name'];
                $description = $input['description'];
                $imageBase64 = $input['imageBase64'];
                $motherboardId = $input['motherboardId'];
                $powerSupplyId = $input['powerSupplyId'];
                $processorId = $input['processorId'];
                $ramMemoryId = $input['ramMemoryId'];
                $ramMemoryAmount = $input['ramMemoryAmount'];
                $storageDevices = $input['storageDevices'];
                $graphicCardId = $input['graphicCardId'];
                $graphicCardAmount = $input['graphicCardAmount'];

                $errors = array();
                //check is something is missing
                if(empty($name)) $errors['name'] = 'Пустой name';
                if(empty($description)) $errors['description'] = 'Пустой description';
                if(empty($imageBase64)) $errors['imageBase64'] = 'Пустой imageBase64';
                if(empty($motherboardId)) $errors['motherboardId'] = 'Пустой motherboardId';
                if(empty($powerSupplyId)) $errors['powerSupplyId'] = 'Пустой powerSupplyId';
                if(empty($processorId)) $errors['processorId'] = 'Пустой processorId';
                if(empty($ramMemoryId)) $errors['ramMemoryId'] = 'Пустой ramMemoryId';
                if(empty($ramMemoryAmount)) $errors['ramMemoryAmount'] = 'Пустой ramMemoryAmount';
                if(empty($storageDevices)) $errors['storageDevices'] = 'Пустой storageDevices';
                if(empty($graphicCardId)) $errors['graphicCardId'] = 'Пустой graphicCardId';
                if(empty($graphicCardAmount)) $errors['graphicCardAmount'] = 'Пустой graphicCardAmount';
                //validate parts
                $errors = validate($errors, $db, $motherboardId, $powerSupplyId, $processorId, $ramMemoryId, $ramMemoryAmount, $storageDevices, $graphicCardId, $graphicCardAmount);
                if(empty($errors)) {
                    http_response_code(201);
                    $ext = explode(';', explode('/', explode(',', $imageBase64)[0])[1])[0];
                    $fileName = uniqid() . '.' . $ext;
                    file_put_contents('images/' . $fileName, file_get_contents($imageBase64));
                    $db->query("INSERT INTO `machine` (`name`, `description`, `imageUrl`, `motherboardId`, `processorId`, `ramMemoryId`, `ramMemoryAmount`, `graphicCardId`, `graphicCardAmount`, `powerSupplyId`) VALUES ('$name', '$description', '$fileName', '$motherboardId', '$processorId', '$ramMemoryId', '$ramMemoryAmount', '$graphicCardId', '$graphicCardAmount', '$powerSupplyId')");
                    $machineId = mysqli_insert_id($db);
                    foreach ($storageDevices as $device) {
                        $id = $device['storageDeviceId'];
                        $amount = $device['amount'];
                        $db->query("INSERT INTO `machineHasStorageDevice` (`machineId`, `storageDeviceId`, `amount`) VALUES ('$machineId', '$id', '$amount')");
                    }
                    echo json_encode(['id' => $machineId, 'motherboardId' => $motherboardId, 'powerSupplyId' => $powerSupplyId, 'processorId' => $processorId, 'ramMemoryId' => $ramMemoryId, 'ramMemoryAmount' => $ramMemoryAmount, 'storageDevices' => $storageDevices, 'graphicCardId' => $graphicCardId, 'graphicCardAmount' => $graphicCardAmount]);
                } else {
                    http_response_code(400);
                    echo json_encode($errors);
                }
                break;
            case 'verify-compatibility': //Check parts compability
                auth($db);
                $errors = array();
                $input = json_decode(file_get_contents('php://input'), true);
                $motherboardId = $input['motherboardId'];
                $powerSupplyId = $input['powerSupplyId'];
                $processorId = $input['processorId'];
                $ramMemoryId = $input['ramMemoryId'];
                $ramMemoryAmount = $input['ramMemoryAmount'];
                $storageDevices = json_decode($input['storageDevices'], true);
                $graphicCardId = $input['graphicCardId'];
                $graphicCardAmount = $input['graphicCardAmount'];

                if(empty($motherboardId)) $errors['motherboardId'] = 'Пустой motherboardId';
                if(empty($powerSupplyId)) $errors['powerSupplyId'] = 'Пустой powerSupplyId';
                $errors = validate($errors, $db, $motherboardId, $powerSupplyId, $processorId, $ramMemoryId, $ramMemoryAmount, $storageDevices, $graphicCardId, $graphicCardAmount);

                if(empty($errors)) echo json_encode(['message' => 'Действующая машина']);
                else {
                    http_response_code(400);
                    echo json_encode($errors);
                }
                break;
        }
        break;

    case 'DELETE':
        switch ($method) {
            case 'logout':
                $token = auth($db);
                $db->query("UPDATE `user` SET `accessToken` = '' WHERE `accessToken`='$token'");
                echo json_encode(['message' => 'Успешный выход']);
                break;
            case 'machines': //Delete machine
                auth($db);
                $result = $db->query("SELECT * FROM `machine` WHERE `id`='$param'");
                if($result->num_rows > 0) {
                    http_response_code(204);
                    try {
                        unlink('images/' . $result->fetch_assoc()['imageUrl']);
                    } catch (Throwable $e) {}
                    $db->query("DELETE FROM `machineHasStorageDevice` WHERE `machineId`='$param'");
                    $db->query("DELETE FROM `machine` WHERE `id`='$param'");
                } else {
                    http_response_code(404);
                    echo json_encode(['message' => 'Модель машины не найдена']);
                }
                break;
        }
        break;
    case 'PUT': 
        switch($method) {
            case 'machines': //update machine
                auth($db);
                $input = json_decode(file_get_contents('php://input'), true);
                $name = $input['name'];
                $description = $input['description'];
                $imageBase64 = $input['imageBase64'];
                $motherboardId = $input['motherboardId'];
                $powerSupplyId = $input['powerSupplyId'];
                $processorId = $input['processorId'];
                $ramMemoryId = $input['ramMemoryId'];
                $ramMemoryAmount = $input['ramMemoryAmount'];
                $storageDevices = $input['storageDevices'];
                $graphicCardId = $input['graphicCardId'];
                $graphicCardAmount = $input['graphicCardAmount'];

                $errors = array();
                //check is something is missing
                if(empty($name)) $errors['name'] = 'Пустой name';
                if(empty($description)) $errors['description'] = 'Пустой description';
                if(empty($motherboardId)) $errors['motherboardId'] = 'Пустой motherboardId';
                if(empty($powerSupplyId)) $errors['powerSupplyId'] = 'Пустой powerSupplyId';
                if(empty($processorId)) $errors['processorId'] = 'Пустой processorId';
                if(empty($ramMemoryId)) $errors['ramMemoryId'] = 'Пустой ramMemoryId';
                if(empty($ramMemoryAmount)) $errors['ramMemoryAmount'] = 'Пустой ramMemoryAmount';
                if(empty($storageDevices)) $errors['storageDevices'] = 'Пустой storageDevices';
                if(empty($graphicCardId)) $errors['graphicCardId'] = 'Пустой graphicCardId';
                if(empty($graphicCardAmount)) $errors['graphicCardAmount'] = 'Пустой graphicCardAmount';
                //validate parts
                $errors = validate($errors, $db, $motherboardId, $powerSupplyId, $processorId, $ramMemoryId, $ramMemoryAmount, $storageDevices, $graphicCardId, $graphicCardAmount);
                if(empty($errors)) {
                    http_response_code(200);
                    if(empty($imageBase64)) {
                        $db->query("DELETE FROM `machineHasStorageDevice` WHERE `machineId`='$param'");
                        $db->query("UPDATE `machine` SET `name`='$name',`description`='$description',`motherboardId`='$motherboardId',`processorId`='$processorId',`ramMemoryId`='$ramMemoryId',`ramMemoryAmount`='$ramMemoryAmount',`graphicCardId`='$graphicCardId',`graphicCardAmount`='$graphicCardAmount',`powerSupplyId`='$powerSupplyId' WHERE `id`='$param'");
                        $machineId = $param;
                        foreach ($storageDevices as $device) {
                            $id = $device['storageDeviceId'];
                            $amount = $device['amount'];
                            $db->query("INSERT INTO `machineHasStorageDevice` (`machineId`, `storageDeviceId`, `amount`) VALUES ('$machineId', '$id', '$amount')");
                        }
                        echo json_encode(['id' => $machineId, 'name' => $name, 'motherboardId' => $motherboardId, 'powerSupplyId' => $powerSupplyId, 'processorId' => $processorId, 'ramMemoryId' => $ramMemoryId, 'ramMemoryAmount' => $ramMemoryAmount, 'storageDevices' => $storageDevices, 'graphicCardId' => $graphicCardId, 'graphicCardAmount' => $graphicCardAmount]);
                    } else {
                        $ext = explode(';', explode('/', explode(',', $imageBase64)[0])[1])[0];
                        $fileName = uniqid() . '.' . $ext;
                        file_put_contents('images/' . $fileName, file_get_contents($imageBase64));
                        $result = $db->query("SELECT * FROM `machine` WHERE `id`='$param'");
                        try {
                            unlink('images/' . $result->fetch_assoc()['imageUrl']);
                        } catch (Throwable $e) {}
                        $db->query("UPDATE `machine` SET `name`='$name',`description`='$description',`imageUrl`='$fileName',`motherboardId`='$motherboardId',`processorId`='$processorId',`ramMemoryId`='$ramMemoryId',`ramMemoryAmount`='$ramMemoryAmount',`graphicCardId`='$graphicCardId',`graphicCardAmount`='$graphicCardAmount',`powerSupplyId`='$powerSupplyId' WHERE `id`='$param'");
                        $machineId = $param;
                        foreach ($storageDevices as $device) {
                            $id = $device['storageDeviceId'];
                            $amount = $device['amount'];
                            $db->query("INSERT INTO `machineHasStorageDevice` (`machineId`, `storageDeviceId`, `amount`) VALUES ('$machineId', '$id', '$amount')");
                        }
                        echo json_encode(['id' => $machineId, 'name' => $name, 'imageUrl' => $fileName, 'motherboardId' => $motherboardId, 'powerSupplyId' => $powerSupplyId, 'processorId' => $processorId, 'ramMemoryId' => $ramMemoryId, 'ramMemoryAmount' => $ramMemoryAmount, 'storageDevices' => $storageDevices, 'graphicCardId' => $graphicCardId, 'graphicCardAmount' => $graphicCardAmount]);
                    }
                } else {
                    http_response_code(400);
                    echo json_encode($errors);
                }
                break;
        }
        break;
}