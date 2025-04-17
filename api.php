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
                'message' => 'Erreur de connexion à l\'API Ridmi',
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

/**
 * Fonction pour récupérer les IPs disponibles en choisissant le préfixe avec la plus faible utilisation
 */
function getALLAvailableIPsForPrefix($netboxUrl, $apiToken, $prefixIds) {
    // Si plusieurs IDs sont fournis, on va tester chacun pour trouver celui avec le plus d'IPs disponibles
    $prefixIdArray = array_map('trim', explode(',', $prefixIds));
    
    // Tableau pour stocker les informations de disponibilité de chaque préfixe
    $prefixesAvailability = [];
    
    // Pour chaque préfixe, obtenir le nombre d'IPs disponibles
    foreach ($prefixIdArray as $prefixId) {
        
        // Obtenir les informations du préfixe
        $prefixInfoEndpoint = "ipam/prefixes/{$prefixId}/";
        $prefixInfoResponse = callNetboxApi($netboxUrl, $apiToken, $prefixInfoEndpoint);
        
        if (!$prefixInfoResponse['success']) {
            continue;
        }
        
        // Récupérer les stats du préfixe
        $prefixStatsEndpoint = "ipam/prefixes/{$prefixId}/available-ips/?limit=1";
        $statsResponse = callNetboxApi($netboxUrl, $apiToken, $prefixStatsEndpoint);
        
        if (!$statsResponse['success']) {
            continue;
        }
        
        // Déterminons le nombre d'IPs disponibles
        $availableIpCount = 0;
        
        // Essayer d'extraire le compte total si disponible dans la réponse
        if (isset($statsResponse['data']['count'])) {
            $availableIpCount = (int)$statsResponse['data']['count'];
        } 
        // Sinon, on peut essayer une autre approche pour estimer la disponibilité
        else if (isset($prefixInfoResponse['data']['prefix'])) {
            $prefixInfo = $prefixInfoResponse['data']['prefix'];
            list($networkPrefix, $cidrMask) = explode('/', $prefixInfo);
            $cidrMask = intval($cidrMask);
            
            // Calculer le nombre total d'adresses dans ce réseau
            $totalAddresses = pow(2, (32 - $cidrMask));
            
            // Tentative de récupération du taux d'utilisation du préfixe si disponible
            $utilizationPercent = isset($prefixInfoResponse['data']['utilization']) ? 
                (float)$prefixInfoResponse['data']['utilization'] : 
                (isset($prefixInfoResponse['data']['utilization_percentage']) ? 
                    (float)$prefixInfoResponse['data']['utilization_percentage'] : null);
            
            if ($utilizationPercent !== null) {
                $availableIpCount = (int)($totalAddresses * (1 - $utilizationPercent / 100));
            } else {
                // Si on ne peut pas déterminer le taux d'utilisation, on fait un échantillonnage rapide
                $sampleEndpoint = "ipam/prefixes/{$prefixId}/available-ips/?limit=100";
                $sampleResponse = callNetboxApi($netboxUrl, $apiToken, $sampleEndpoint);
                
                if ($sampleResponse['success']) {
                    $data = $sampleResponse['data'];
                    $sampleCount = 0;
                    
                    if (isset($data['results']) && is_array($data['results'])) {
                        $sampleCount = count($data['results']);
                    } else if (is_array($data)) {
                        $sampleCount = count($data);
                    }
                    
                    // On estime le total en fonction de l'échantillon
                    $availableIpCount = $sampleCount > 0 ? $sampleCount : 10; // Valeur arbitraire si échantillon vide
                }
            }
        }
               
        // Stocker le préfixe et son nombre d'IPs disponibles
        $prefixesAvailability[$prefixId] = $availableIpCount;
    }
    
    // Si aucun préfixe n'a pu être évalué, prendre un préfixe au hasard
    if (empty($prefixesAvailability)) {
        $selectedPrefixId = $prefixIdArray[array_rand($prefixIdArray)];
    } else {
        // Sinon, choisir le préfixe avec le plus d'IPs disponibles
        arsort($prefixesAvailability); // Trier par ordre décroissant
        $selectedPrefixId = key($prefixesAvailability); // Prendre le premier élément (le plus grand)
    }
    
    // Maintenant, récupérer les IPs disponibles du préfixe sélectionné
    $allIPs = [];
    $networkAddress = null;
    $broadcastAddress = null;
    $firstUsableAddress = null;
    $lastUsableAddress = null;
    
    // Obtenir les informations du préfixe sélectionné
    $prefixInfoEndpoint = "ipam/prefixes/{$selectedPrefixId}/";
    $prefixInfoResponse = callNetboxApi($netboxUrl, $apiToken, $prefixInfoEndpoint);
    
    if ($prefixInfoResponse['success'] && isset($prefixInfoResponse['data']['prefix'])) {
        $prefixInfo = $prefixInfoResponse['data']['prefix'];
        
        // Extraire l'adresse réseau et le masque
        list($networkPrefix, $cidrMask) = explode('/', $prefixInfo);
        $cidrMask = intval($cidrMask);
        
        // Calculer le nombre d'adresses dans ce réseau
        $numAddresses = pow(2, (32 - $cidrMask));
        
        // Convertir l'adresse réseau en entier
        $networkLong = ip2long($networkPrefix);
        
        // L'adresse de broadcast est l'adresse réseau + nombre d'adresses - 1
        $broadcastLong = $networkLong + $numAddresses - 1;
        
        // Convertir en notation IP standard
        $networkAddress = long2ip($networkLong);
        $broadcastAddress = long2ip($broadcastLong);
        
        // Les premières et dernières adresses utilisables
        $firstUsableLong = $networkLong + 1;
        $lastUsableLong = $broadcastLong - 1;
        
        $firstUsableAddress = long2ip($firstUsableLong);
        $lastUsableAddress = long2ip($lastUsableLong);
        
    }
    
    // Récupérer les IPs disponibles
    $requestLimit = 8190; // Valeur maximale pour être sûr d'avoir assez d'IPs
    $endpoint = "ipam/prefixes/{$selectedPrefixId}/available-ips/?limit={$requestLimit}";
    $response = callNetboxApi($netboxUrl, $apiToken, $endpoint);
    
    if (!$response['success']) {
        throw new Exception("Le préfixe n°{$selectedPrefixId} n'a plus d'adresses disponibles");
    }
    
    $data = $response['data'];
    $foundIPs = [];
    
    // Traiter les réponses selon le format de données
    if (is_array($data)) {
        if (isset($data['results']) && is_array($data['results'])) {
            // Format avec 'results'
            foreach ($data['results'] as $item) {
                if (isset($item['address'])) {
                    $ipPart = explode('/', $item['address'])[0];
                    $foundIPs[] = $ipPart;
                }
            }
        } else {
            // Format direct
            foreach ($data as $item) {
                if (isset($item['address'])) {
                    $ipPart = explode('/', $item['address'])[0];
                    $foundIPs[] = $ipPart;
                }
            }
        }
    }
    
    // Si nous n'avons pas trouvé d'IPs par les méthodes standard, 
    // essayer d'extraire des adresses IP à partir de la réponse brute
    if (empty($foundIPs)) {
        $jsonString = json_encode($data);
        preg_match_all('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $jsonString, $matches);
        if (!empty($matches[0])) {
            $foundIPs = array_unique($matches[0]);
        }
    }
    
    // Éliminer les doublons
    $allIPs = array_unique($foundIPs);
    
    // Filtrer les IPs pour exclure l'adresse réseau, broadcast, première et dernière utilisables
    $filteredIPs = [];
    
    foreach ($allIPs as $ip) {
        // Exclure les adresses spéciales si nous les connaissons
        if ($ip != $networkAddress && $ip != $broadcastAddress && 
            $ip != $firstUsableAddress && $ip != $lastUsableAddress) {
            $filteredIPs[] = $ip;
        }
    }
    
    return $filteredIPs;
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
                echo json_encode(['success' => false, 'message' => 'URL Ridmi et token API requis']);
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
                echo json_encode(['success' => false, 'message' => 'URL Ridmi et token API requis']);
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
                    'message' => 'Impossible de se connecter à Ridmi: ' . $connectionCheck['message'],
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
            
            case 'search_available_ips':
                if (!checkRequiredFields(['netbox_url', 'api_token', 'region', 'exposition', 'url_type', 'config_data'], $requestData)) {
                    echo json_encode(['success' => false, 'message' => 'Données insuffisantes pour la recherche d\'IPs']);
                    exit;
                }
                
                $netboxUrl = $requestData['netbox_url'];
                $apiToken = $requestData['api_token'];
                $configData = $requestData['config_data'];
                
                // Récupérer les IDs de préfixes depuis la configuration
                $epsIds = $configData['eps'] ?? '';
                $vipLbNhdcIds = $configData['vipLbNhdc'] ?? '';
                $vipLbNbdcIds = $configData['vipLbNbdc'] ?? '';
                
                // Traitement spécifique pour WAF qui peut être un tableau ou une chaîne
                $wafIds = '';
                $wafConfig = $configData['waf'] ?? [];
                
                if (is_array($wafConfig)) {
                    // Si c'est un tableau de tableaux, sélectionner aléatoirement un tableau
                    if (isset($wafConfig[0]) && is_array($wafConfig[0])) {
                        $selectedWafArray = $wafConfig[array_rand($wafConfig)];
                        $wafIds = implode(',', array_filter($selectedWafArray, 'is_string'));
                    } else {
                        $wafIds = implode(',', array_filter($wafConfig, 'is_string'));
                    }
                } else if (is_string($wafConfig)) {
                    $wafIds = $wafConfig;
                }
                
                if (empty($wafIds)) {
                    echo json_encode(['success' => false, 'message' => 'Configuration WAF invalide ou manquante']);
                    exit;
                }
                
                // Récupérer les IPs disponibles pour chaque préfixe
                try {
                    $allEpsIPs = getALLAvailableIPsForPrefix($netboxUrl, $apiToken, $epsIds);
                } catch (Exception $e) {
                    if (empty($filteredEpsIPs)) {
                        $totalEpsIPs = count($allEpsIPs); // Nombre total avant filtrage
                        
                        echo json_encode([
                            'success' => false,
                            'message' => "Aucune IP valide disponible pour EPS - Préfixe: {$epsIds}. " .
                                         "Total avant filtrage: {$totalEpsIPs} IPs. " .
                                         "Les IPs trouvées ne respectent pas les contraintes (octets 0, 1, 254, 255 exclus)"
                        ]);
                        exit;
                    }
                }
                
                try {
                    $allVipLbNhdcIPs = getALLAvailableIPsForPrefix($netboxUrl, $apiToken, $vipLbNhdcIds);
                } catch (Exception $e) {
                    echo json_encode([
                        'success' => false,
                        'message' => "CDS en échec : aucune IP disponible pour VIP LB NHDC: " . $e->getMessage()
                    ]);
                    exit;
                }
                
                try {
                    $allWafIPs = getALLAvailableIPsForPrefix($netboxUrl, $apiToken, $wafIds);
                } catch (Exception $e) {
                    echo json_encode([
                        'success' => false,
                        'message' => "CDS en échec : aucune IP disponible pour WAF: " . $e->getMessage()
                    ]);
                    exit;
                }
                
                try {
                    $allVipLbNbdcIPs = getALLAvailableIPsForPrefix($netboxUrl, $apiToken, $vipLbNbdcIds);
                } catch (Exception $e) {
                    echo json_encode([
                        'success' => false,
                        'message' => "CDS en échec : aucune IP disponible pour VIP LB NBDC: " . $e->getMessage()
                    ]);
                    exit;
                }
                
                // Vérification préliminaire
                if (empty($allVipLbNhdcIPs) || empty($allWafIPs) || empty($allEpsIPs) || empty($allVipLbNbdcIPs)) {
                    $missing = [];
                    if (empty($allEpsIPs)) $missing[] = "EPS (préfixe: {$epsIds})";
                    if (empty($allVipLbNhdcIPs)) $missing[] = "VIP LB NHDC (préfixe: {$vipLbNhdcIds})";
                    if (empty($allWafIPs)) $missing[] = "WAF (préfixe: {$wafIds})";
                    if (empty($allVipLbNbdcIPs)) $missing[] = "VIP LB NBDC (préfixe: {$vipLbNbdcIds})";
                    
                    echo json_encode([
                        'success' => false,
                        'message' => "Aucune IP disponible pour: " . implode(", ", $missing)
                    ]);
                    exit;
                }
                
                // Filtrer les IPs pour exclure celles avec dernier octet 0 ou 255
                $filteredVipLbNhdcIPs = [];
                $filteredWafIPs = [];
                $filteredEpsIPs = [];
                $filteredVipLbNbdcIPs = [];
                
                // Filtrer VIP LB NHDC
                foreach ($allVipLbNhdcIPs as $ip) {
                    if (preg_match('/\.(\d+)$/', $ip, $matches)) {
                        $lastOctet = (int)$matches[1];
                        if ($lastOctet > 1 && $lastOctet < 254) {
                            $filteredVipLbNhdcIPs[] = $ip;
                        }
                    }
                }
                
                // Filtrer WAF
                foreach ($allWafIPs as $ip) {
                    if (preg_match('/\.(\d+)$/', $ip, $matches)) {
                        $lastOctet = (int)$matches[1];
                        if ($lastOctet > 1 && $lastOctet < 254) {
                            $filteredWafIPs[] = $ip;
                        }
                    }
                }
                
                // Filtrer EPS
                foreach ($allEpsIPs as $ip) {
                    if (preg_match('/\.(\d+)$/', $ip, $matches)) {
                        $lastOctet = (int)$matches[1];
                        if ($lastOctet > 1 && $lastOctet < 254) {
                            $filteredEpsIPs[] = $ip;
                        }
                    }
                }
                
                // Filtrer VIP LB NBDC
                foreach ($allVipLbNbdcIPs as $ip) {
                    if (preg_match('/\.(\d+)$/', $ip, $matches)) {
                        $lastOctet = (int)$matches[1];
                        if ($lastOctet > 1 && $lastOctet < 254) {
                            $filteredVipLbNbdcIPs[] = $ip;
                        }
                    }
                }
                
                // Vérifier qu'il reste des IPs après filtrage
                if (empty($filteredVipLbNhdcIPs) || empty($filteredWafIPs) || empty($filteredEpsIPs) || empty($filteredVipLbNbdcIPs)) {
                    $missing = [];
                    if (empty($filteredEpsIPs)) $missing[] = "EPS (préfixe: {$epsIds}) - Pas d'IPs valides après filtrage";
                    if (empty($filteredVipLbNhdcIPs)) $missing[] = "VIP LB NHDC (préfixe: {$vipLbNhdcIds}) - Pas d'IPs valides après filtrage";
                    if (empty($filteredWafIPs)) $missing[] = "WAF (préfixe: {$wafIds}) - Pas d'IPs valides après filtrage";
                    if (empty($filteredVipLbNbdcIPs)) $missing[] = "VIP LB NBDC (préfixe: {$vipLbNbdcIds}) - Pas d'IPs valides après filtrage";
                    
                    echo json_encode([
                        'success' => false,
                        'message' => "Aucune IP valide disponible après filtrage pour: " . implode(", ", $missing)
                    ]);
                    exit;
                }
                
                // Organiser les IPs WAF par dernier octet pour faciliter la recherche
                $wafIPsByLastOctet = [];
                foreach ($filteredWafIPs as $ip) {
                    if (preg_match('/\.(\d+)$/', $ip, $matches)) {
                        $lastOctet = $matches[1];
                        if (!isset($wafIPsByLastOctet[$lastOctet])) {
                            $wafIPsByLastOctet[$lastOctet] = [];
                        }
                        $wafIPsByLastOctet[$lastOctet][] = $ip;
                    }
                }
                
                // Parcourir les IPs VIP LB NHDC et chercher une combinaison valide avec WAF
                $selectedVipLbNhdc = null;
                $selectedLastOctet = null;
                $selectedWafIPs = [];
                
                foreach ($filteredVipLbNhdcIPs as $vipLbNhdcIP) {
                    if (preg_match('/\.(\d+)$/', $vipLbNhdcIP, $matches)) {
                        $lastOctet = $matches[1];
                        
                        // Vérifier si nous avons au moins 5 IPs WAF avec ce dernier octet
                        if (isset($wafIPsByLastOctet[$lastOctet]) && count($wafIPsByLastOctet[$lastOctet]) >= 5) {
                            $selectedVipLbNhdc = $vipLbNhdcIP;
                            $selectedLastOctet = $lastOctet;
                            // Prendre les 5 premières IPs WAF avec ce dernier octet
                            $selectedWafIPs = array_values($wafIPsByLastOctet[$lastOctet]);
                            $selectedWafIPs = array_slice($selectedWafIPs, 0, 5);
                            break;
                        }
                    }
                }
                
                // Si nous n'avons pas trouvé de combinaison valide pour NHDC et WAF
                if (!$selectedVipLbNhdc || !$selectedLastOctet || count($selectedWafIPs) < 5) {
                    echo json_encode([
                        'success' => false,
                        'message' => "Impossible de trouver 5 IPs WAF avec le même dernier octet qu'une IP VIP LB NHDC. Préfixes vérifiés: VIP LB NHDC ({$vipLbNhdcIds}), WAF ({$wafIds})"
                    ]);
                    exit;
                }
                
                // Pour EPS et VIP LB NBDC, nous prenons la première IP disponible
                // (elles ont déjà été filtrées pour éviter les octets 0, 1, 254 et 255)
                $selectedEps = !empty($filteredEpsIPs) ? $filteredEpsIPs[0] : null;
                $selectedVipLbNbdc = !empty($filteredVipLbNbdcIPs) ? $filteredVipLbNbdcIPs[0] : null;
                
                // Vérifier que nous avons bien trouvé des IPs valides
                if (!$selectedEps || !$selectedVipLbNbdc) {
                    $missing = [];
                    if (!$selectedEps) $missing[] = "EPS (préfixe: {$epsIds})";
                    if (!$selectedVipLbNbdc) $missing[] = "VIP LB NBDC (préfixe: {$vipLbNbdcIds})";
                    
                    echo json_encode([
                        'success' => false,
                        'message' => "Aucune IP valide disponible pour: " . implode(", ", $missing)
                    ]);
                    exit;
                }
                
                // Nous avons trouvé une combinaison valide !
                $selectedIPs = [
                    'eps' => $selectedEps,
                    'vipLbNhdc' => $selectedVipLbNhdc,
                    'wafIPs' => $selectedWafIPs,
                    'vipLbNbdc' => $selectedVipLbNbdc,
                    'parentPrefixes' => [
                        'eps' => $epsIds,  // Réutiliser les IDs de préfixes utilisés pour la recherche
                        'vipLbNhdc' => $vipLbNhdcIds,
                        'waf' => $wafIds,
                        'vipLbNbdc' => $vipLbNbdcIds
                    ]
                ];
                echo json_encode([
                    'success' => true,
                    'data' => $selectedIPs,
                    'message' => "IPs disponibles trouvées avec succès (dernier octet NHDC/WAF: " . $selectedLastOctet . ")"
                ]);
                break;

        case 'provision_resources':
            if (!checkRequiredFields(['netbox_url', 'api_token', 'region', 'exposition', 'url_type', 'specific_url', 'description', 'ips'], $requestData)) {
                echo json_encode(['success' => false, 'message' => 'Données insuffisantes pour le provisionnement']);
                exit;
            }
            
            $netboxUrl = $requestData['netbox_url'];
            $apiToken = $requestData['api_token'];
            $region = $requestData['region'];
            $exposition = $requestData['exposition'];
            $urlType = $requestData['url_type'];
            $specificUrl = $requestData['specific_url'];
            $description = $requestData['description'];
            $ips = $requestData['ips'];
            
            // Vérifier que toutes les IPs nécessaires sont présentes
            if (!isset($ips['eps']) || !isset($ips['vipLbNhdc']) || !isset($ips['wafIPs']) || !isset($ips['vipLbNbdc'])) {
                echo json_encode(['success' => false, 'message' => 'Données d\'IPs incomplètes pour le provisionnement']);
                exit;
            }
            
            // Préparer la description de base
            $baseDescription = "{$description} - {$specificUrl} - {$region} - {$exposition} - {$urlType}";
            
            // Provisionner chaque composant
            $results = [];
            
            // 1. EPS (IP publique)
            $epsDescription = "{$baseDescription} - EPS";
            $epsResult = createIPAddress($netboxUrl, $apiToken, $ips['eps'], $epsDescription, ['CDS', 'EPS']);
            $results['eps'] = $epsResult;
            
            // 2. VIP LB NHDC
            $vipLbNhdcDescription = "{$baseDescription} - VIP LB NHDC";
            $vipLbNhdcResult = createIPAddress($netboxUrl, $apiToken, $ips['vipLbNhdc'], $vipLbNhdcDescription, ['CDS', 'VIP_LB_NHDC']);
            $results['vipLbNhdc'] = $vipLbNhdcResult;
            
            // 3. WAF IPs
            $wafResults = [];
            foreach ($ips['wafIPs'] as $index => $wafIp) {
                $wafDescription = "{$baseDescription} - WAF #" . ($index + 1);
                $wafResult = createIPAddress($netboxUrl, $apiToken, $wafIp, $wafDescription, ['CDS', 'WAF']);
                $wafResults[] = $wafResult;
            }
            $results['waf'] = $wafResults;
            
            // 4. VIP LB NBDC
            $vipLbNbdcDescription = "{$baseDescription} - VIP LB NBDC";
            $vipLbNbdcResult = createIPAddress($netboxUrl, $apiToken, $ips['vipLbNbdc'], $vipLbNbdcDescription, ['CDS', 'VIP_LB_NBDC']);
            $results['vipLbNbdc'] = $vipLbNbdcResult;
            
            // Vérifier si tous les provisionnements ont réussi
            $success = true;
            $messages = [];
            
            // Vérifier EPS
            if (!($results['eps']['success'] ?? false)) {
                $success = false;
                $messages[] = "Échec de provisionnement EPS: " . ($results['eps']['message'] ?? 'Erreur inconnue');
            }
            
            // Vérifier VIP LB NHDC
            if (!($results['vipLbNhdc']['success'] ?? false)) {
                $success = false;
                $messages[] = "Échec de provisionnement VIP LB NHDC: " . ($results['vipLbNhdc']['message'] ?? 'Erreur inconnue');
            }
            
            // Vérifier WAF
            foreach ($results['waf'] as $index => $result) {
                if (!($result['success'] ?? false)) {
                    $success = false;
                    $messages[] = "Échec de provisionnement WAF #" . ($index + 1) . ": " . ($result['message'] ?? 'Erreur inconnue');
                }
            }
            
            // Vérifier VIP LB NBDC
            if (!($results['vipLbNbdc']['success'] ?? false)) {
                $success = false;
                $messages[] = "Échec de provisionnement VIP LB NBDC: " . ($results['vipLbNbdc']['message'] ?? 'Erreur inconnue');
            }
            
            // Préparer et envoyer la réponse
            echo json_encode([
                'success' => $success,
                'actions' => $results,
                'message' => $success ? 'Provisionnement réussi pour toutes les IPs' : implode('; ', $messages)
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Action inconnue: ' . $action]);
    }
} catch (Exception $e) {
    error_log('Erreur API: ' . $e->getMessage());
    error_log('Trace: ' . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => 'Erreur serveur: ' . $e->getMessage()
    ]);
}
?>