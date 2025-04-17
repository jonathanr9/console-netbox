<?php
// Configuration de base
ini_set('display_errors', 0);
header('Content-Type: application/json');

// Fonction pour vérifier les champs requis
function checkRequiredFields($fields, $data) {
    foreach ($fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return false;
        }
    }
    return true;
}

// Fonction pour appeler l'API Netbox
function callNetboxApi($url, $token, $endpoint, $method = 'GET', $data = null) {
    $fullUrl = rtrim($url, '/') . '/api/' . ltrim($endpoint, '/');
    
    // Informations de débogage
    $debug = [
        'request_time' => microtime(true),
        'url' => $fullUrl,
        'method' => $method
    ];

    $options = [
        'http' => [
            'header' => "Authorization: Token " . $token . "\r\n" .
                        "Accept: application/json\r\n" .
                        "Content-Type: application/json\r\n",
            'method' => $method,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];
    
    if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
        $options['http']['content'] = json_encode($data);
    }
    
    $context = stream_context_create($options);
    
    try {
        $response = file_get_contents($fullUrl, false, $context);
        
        // Calculer le temps de réponse
        $debug['response_time'] = microtime(true) - $debug['request_time'];
        $debug['response_time_ms'] = round($debug['response_time'] * 1000);
        
        // Obtenir les en-têtes de réponse
        $responseHeaders = $http_response_header ?? [];
        $statusCode = 500;
        $debug['response_headers'] = $responseHeaders;
        
        foreach ($responseHeaders as $header) {
            if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                $statusCode = (int)$matches[1];
                break;
            }
        }
        
        if ($response === false) {
            $debug['error'] = 'file_get_contents returned false';
            return [
                'success' => false,
                'message' => 'Erreur de connexion à l\'API netbox',
                'debug' => $debug
            ];
        }
        
        $responseData = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $debug['json_error'] = json_last_error_msg();
            $debug['raw_response_sample'] = substr($response, 0, 500);
            return [
                'success' => false,
                'message' => 'Erreur de décodage JSON',
                'debug' => $debug
            ];
        }
        
        if ($statusCode == 401 || $statusCode == 403) {
            return [
                'success' => false,
                'message' => 'Erreur d\'authentification: Token API invalide',
                'error_type' => 'auth_error',
                'data' => $responseData
            ];
        }    

        if ($statusCode >= 200 && $statusCode < 300) {
            return [
                'success' => true, 
                'data' => $responseData,
                'debug' => $debug
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Erreur API: ' . ($responseData['detail'] ?? 'Code HTTP ' . $statusCode),
                'data' => $responseData,
                'debug' => $debug
            ];
        }
    } catch (Exception $e) {
        $debug['exception'] = $e->getMessage();
        $debug['trace'] = $e->getTraceAsString();
        return [
            'success' => false,
            'message' => 'Exception: ' . $e->getMessage(),
            'debug' => $debug
        ];
    }
}

/**
 * Fonction pour créer une adresse IP dans NetBox
 */
function createIPAddress($netboxUrl, $apiToken, $address, $description, $tags = []) {
    // S'assurer que l'adresse a un préfixe CIDR
    if (strpos($address, '/') === false) {
        $address .= '/32';  // Ajouter un masque /32 par défaut pour les adresses individuelles
    }
    
    $ipData = [
        'address' => $address,
        'description' => $description,
        'status' => 'active'
    ];
    
    if (!empty($tags)) {
        $ipData['tags'] = $tags;
    }
    
    return callNetboxApi($netboxUrl, $apiToken, 'ipam/ip-addresses/', 'POST', $ipData);
}
try {
    // Récupérer les données de la requête
    $raw_input = file_get_contents('php://input');
    $requestData = json_decode($raw_input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $requestData = $_POST ?: $_GET;
    }
    
    // Action par défaut
    $action = $requestData['action'] ?? ($_GET['action'] ?? 'test');
    
    // Traiter l'action demandée
    switch ($action) {
        case 'test':
            echo json_encode([
                'success' => true,
                'message' => 'API fonctionnelle'
            ]);
            break;
            
        case 'verify_connection':
            if (!checkRequiredFields(['netbox_url', 'api_token'], $requestData)) {
                echo json_encode(['success' => false, 'message' => 'URL netbox et token API requis']);
                exit;
            }
            
            $netboxUrl = $requestData['netbox_url'];
            $apiToken = $requestData['api_token'];
            
            $result = callNetboxApi($netboxUrl, $apiToken, '');
            $result['connection_details'] = [
                'url' => $netboxUrl,
                'token_prefix' => substr($apiToken, 0, 4) . '****' . substr($apiToken, -4),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            echo json_encode($result);
            break;
            
        case 'get_data':
            if (!checkRequiredFields(['netbox_url', 'api_token'], $requestData)) {
                echo json_encode(['success' => false, 'message' => 'URL netbox et token API requis']);
                exit;
            }
            
            $netboxUrl = $requestData['netbox_url'];
            $apiToken = $requestData['api_token'];
            
            $connectionCheck = callNetboxApi($netboxUrl, $apiToken, '');
            if (!$connectionCheck['success']) {
                if (isset($connectionCheck['error_type']) && $connectionCheck['error_type'] === 'auth_error') {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Connexion impossible - Token API incorrect',
                        'error_details' => $connectionCheck['message'],
                        'error_type' => 'auth_error'
                    ]);
                    exit;
                }
                
                echo json_encode([
                    'success' => false,
                    'message' => 'Impossible de se connecter à netbox: ' . $connectionCheck['message'],
                    'error_type' => 'connection_error'
                ]);
                exit;
            }
                    
            $netboxUrl = $requestData['netbox_url'];
            $apiToken = $requestData['api_token'];
            
            // Données de base avec statuts prédéfinis
            $data = [
                'roles' => [],
                'sites' => [],
                'tenants' => [],
                'clusters' => [],
                'statuses' => [
                    ['id' => 'active', 'name' => 'Active'],
                    ['id' => 'offline', 'name' => 'Offline'],
                    ['id' => 'planned', 'name' => 'Planned'],
                    ['id' => 'staged', 'name' => 'Staged'],
                    ['id' => 'failed', 'name' => 'Failed'],
                    ['id' => 'decommissioning', 'name' => 'Decommissioning']
                ]
            ];
            
            // Récupérer les données de NetBox
            $roleResult = callNetboxApi($netboxUrl, $apiToken, 'dcim/device-roles/?limit=1000');    
            if ($roleResult['success']) {
                $data['roles'] = $roleResult['data']['results'] ?? [];
            }
            
            $siteResult = callNetboxApi($netboxUrl, $apiToken, 'dcim/sites/?limit=1000');
            if ($siteResult['success']) {
                $data['sites'] = $siteResult['data']['results'] ?? [];
            }
            
            $tenantResult = callNetboxApi($netboxUrl, $apiToken, 'tenancy/tenants/?limit=1000');
            if ($tenantResult['success']) {
                $data['tenants'] = $tenantResult['data']['results'] ?? [];
            }
            
            $clusterResult = callNetboxApi($netboxUrl, $apiToken, 'virtualization/clusters/?limit=1000');
            if ($clusterResult['success']) {
                $data['clusters'] = $clusterResult['data']['results'] ?? [];
            }
            
            $allSuccess = ($roleResult['success'] ?? false) && 
                          ($siteResult['success'] ?? false) && 
                          ($tenantResult['success'] ?? false) && 
                          ($clusterResult['success'] ?? false);
                          
            echo json_encode([
                'success' => $allSuccess,
                'data' => $data,
                'debug_info' => $debug_info ?? null,
                'message' => $allSuccess ? 'Données récupérées avec succès' : 'Certaines données n\'ont pas pu être récupérées'
            ]);
            break;
            
        case 'create_vm':
            if (!checkRequiredFields(['netbox_url', 'api_token', 'vm_name', 'site_id', 'role_id', 'tenant_id', 'cluster_id'], $requestData)) {
                echo json_encode(['success' => false, 'message' => 'Champs obligatoires manquants']);
                exit;
            }
            
            $netboxUrl = $requestData['netbox_url'];
            $apiToken = $requestData['api_token'];
            
            // Préparer les données pour la création de VM
            $vmData = [
                'name' => $requestData['vm_name'],
                'site' => $requestData['site_id'],
                'role_id' => $requestData['role_id'],
                'tenant_id' => $requestData['tenant_id'],
                'cluster_id' => $requestData['cluster_id'],
                'status' => $requestData['status'] ?? 'active'
            ];
            
            // Ajouter les champs optionnels
            if (!empty($requestData['description'])) $vmData['description'] = $requestData['description'];
            if (!empty($requestData['vcpus'])) $vmData['vcpus'] = $requestData['vcpus'];
            if (!empty($requestData['memory'])) $vmData['memory'] = $requestData['memory'];
            
            // Créer la VM
            $result = callNetboxApi($netboxUrl, $apiToken, 'virtualization/virtual-machines/', 'POST', $vmData);
            $result['request_data'] = [
                'vm_data' => $vmData,
                'endpoint' => 'virtualization/virtual-machines/'
            ];
            
            // Si la VM a été créée, ajouter les disques si nécessaire
            if (($result['success'] ?? false) && !empty($requestData['disks']) && is_array($requestData['disks'])) {
                $vmId = $result['data']['id'] ?? null;
                
                if ($vmId) {
                    $disksResults = [];
                    
                    foreach ($requestData['disks'] as $index => $disk) {
                        if (!empty($disk['name']) && !empty($disk['size'])) {
                            $diskData = [
                                'virtual_machine' => $vmId,
                                'name' => $disk['name'],
                                'size' => (int)$disk['size'],
                                'status' => 'active'
                            ];
                            
                            if (!empty($disk['description'])) {
                                $diskData['description'] = $disk['description'];
                            } else {
                                $diskData['description'] = "Disque pour " . $requestData['vm_name'];
                            }
                            
                            $diskResult = callNetboxApi($netboxUrl, $apiToken, 'virtualization/virtual-disks/', 'POST', $diskData);
                            $diskResult['request_data'] = [
                                'disk_data' => $diskData,
                                'disk_index' => $index
                            ];                
                            $disksResults[] = $diskResult;
                        }
                    }
                    
                    $result['disks_results'] = $disksResults;
                }
            }
            
            // Si la VM a été créée, ajouter les interfaces si nécessaire
            if (($result['success'] ?? false) && !empty($requestData['interfaces']) && is_array($requestData['interfaces'])) {
                $vmId = $result['data']['id'] ?? null;
                
                if ($vmId) {
                    $interfacesResults = [];
                    
                    foreach ($requestData['interfaces'] as $index => $interface) {
                        if (!empty($interface['name']) && !empty($interface['ip_address'])) {
                            // Créer d'abord l'interface
                            $interfaceData = [
                                'virtual_machine' => $vmId,
                                'name' => $interface['name'],
                                'description' => $interface['description'] ?? ''
                            ];
                            
                            $interfaceResult = callNetboxApi($netboxUrl, $apiToken, 'virtualization/interfaces/', 'POST', $interfaceData);
                            $interfaceResult['request_data'] = [
                                'interface_data' => $interfaceData,
                                'interface_index' => $index
                            ];
                
                            
                            // Si l'interface est créée avec succès, lui attribuer l'IP
                            if ($interfaceResult['success'] && isset($interfaceResult['data']['id'])) {
                                $interfaceId = $interfaceResult['data']['id'];
                                
                                // Créer l'adresse IP
                                $ipAddressData = [
                                    'address' => strpos($interface['ip_address'], '/') !== false 
                                        ? $interface['ip_address'] 
                                        : $interface['ip_address'] . '/24', // Ajouter un masque par défaut si absent
                                    'assigned_object_type' => 'virtualization.vminterface',
                                    'assigned_object_id' => $interfaceId,
                                    'status' => 'active'
                                ];
                                
                                $ipResult = callNetboxApi($netboxUrl, $apiToken, 'ipam/ip-addresses/', 'POST', $ipAddressData);
                                $ipResult['request_data'] = [
                                    'ip_data' => $ipAddressData
                                ];
                                
                                // Stocker les résultats
                                $interfacesResults[] = [
                                    'interface' => $interfaceResult,
                                    'ip_address' => $ipResult
                                ];
                            } else {
                                $interfacesResults[] = [
                                    'interface' => $interfaceResult,
                                    'ip_address' => null
                                ];
                            }
                        }
                    }
                    
                    $result['interfaces_results'] = $interfacesResults;
                }
            }

            echo json_encode($result);
            break;
            
        }
    }
?>