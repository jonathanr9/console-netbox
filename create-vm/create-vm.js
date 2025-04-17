import { validateVmName } from "./validateVmName.js";
import { checkVmExists } from "./check_vm_exist.js";

// Variables globales pour stocker l'état
let netboxData = {
    url: null,
    token: null,
    data: {
        roles: [],
        sites: [],
        tenants: [],
        clusters: [],
        statuses: [
            {id: 'active', name: 'Active'},
            {id: 'offline', name: 'Offline'},
            {id: 'planned', name: 'Planned'},
            {id: 'staged', name: 'Staged'},
            {id: 'failed', name: 'Failed'},
            {id: 'decommissioning', name: 'Decommissioning'}
        ]
    },
    vm: {
        name: null,
        description: null,
        site_id: null,
        role_id: null,
        tenant_id: null,
        cluster_id: null,
        vcpus: null,
        memory: null,
        status: null,
        interfaces: [],
        disks: []
    }
};

// Compteurs
let diskCounter = 1;
let interfaceCounter = 1;

// Initialisation
document.addEventListener('DOMContentLoaded', function() {
    populateDropdown('vm-status', netboxData.data.statuses);
    document.getElementById('connect-button').addEventListener('click', connectToNetbox);
    document.getElementById('back-to-step1').addEventListener('click', () => goToStep(1));
    document.getElementById('next-to-step3').addEventListener('click', goToStep3);
    document.getElementById('back-to-step2').addEventListener('click', () => goToStep(2));
    document.getElementById('add-disk-button').addEventListener('click', addDiskField);
    document.getElementById('add-interface-button').addEventListener('click', addInterfaceField);
    document.getElementById('create-button').addEventListener('click', createVM);



    // Définition des fonctions pour les boutons de suppression
    window.removeDisk = removeDisk;
    window.removeInterface = removeInterface;
});


// Fonction pour se connecter à NetBox
function connectToNetbox() {
    const netboxUrl = document.getElementById('netbox-url').value;
    const apiToken = document.getElementById('api-token').value;
    
    if (!netboxUrl || !apiToken) {
        alert('Veuillez remplir tous les champs.');
        return;
    }
    
    document.getElementById('connect-spinner').style.display = 'block';
    document.getElementById('connect-error').style.display = 'none';
    document.getElementById('connect-button').disabled = true;
    
    callApi('get_data', { netbox_url: netboxUrl, api_token: apiToken })
    .then(response => {
        document.getElementById('connect-spinner').style.display = 'none';
        document.getElementById('connect-button').disabled = false;
        
        if (response.success) {

            netboxData.url = netboxUrl;
            netboxData.token = apiToken;
            
            if (response.data.roles) netboxData.data.roles = response.data.roles;
            if (response.data.sites) netboxData.data.sites = response.data.sites;
            if (response.data.tenants) netboxData.data.tenants = response.data.tenants;
            if (response.data.clusters) netboxData.data.clusters = response.data.clusters;
            
            populateDropdown('device-role', netboxData.data.roles);
            populateDropdown('device-site', netboxData.data.sites);
            populateDropdown('vm-tenant', netboxData.data.tenants);
            populateDropdown('vm-cluster', netboxData.data.clusters);
            
            // Passer directement à l'étape de configuration
            goToStep(2);
        } else {
            const errorDiv = document.getElementById('connect-error');
            errorDiv.style.display = 'block';
            
            // Construire un message d'erreur plus détaillé
            let debugInfo = '';
            if (response.debug_info) {
                debugInfo = `
                <details>
                    <summary>Informations de débogage</summary>
                    <pre>${JSON.stringify(response.debug_info, null, 2)}</pre>
                </details>`;
            }
            
            // Ajouter les détails de débogage si disponibles
            let debugDetails = '';
            if (response.debug) {
                debugDetails = `
                <details>
                    <summary>Détails techniques</summary>
                    <p>URL: ${response.debug.url || 'Non disponible'}</p>
                    <p>Temps de réponse: ${response.debug.response_time_ms || '?'} ms</p>
                    <p>Code HTTP: ${response.debug.status_code || 'Non disponible'}</p>
                </details>`;
            }
            
            errorDiv.innerHTML = `
                <h3>Erreur de connexion</h3>
                <p>${response.message || 'Impossible de se connecter à netbox'}</p>
                ${debugInfo}
                ${debugDetails}
            `;
        }
    })
    .catch(error => {
        document.getElementById('connect-spinner').style.display = 'none';
        document.getElementById('connect-button').disabled = false;
        
        const errorDiv = document.getElementById('connect-error');
        errorDiv.style.display = 'block';
        errorDiv.innerHTML = `
            <h3>Erreur de communication</h3>
            <p>Impossible de communiquer avec le serveur.</p>
            <p>Détails: ${error.message}</p>
        `;
    });
}

// Fonction pour appeler l'API
async function callApi(action, data) {
    try {
        // Chemin relatif ajusté pour la structure en dossiers
        const response = await fetch(`../api.php?action=${action}`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        
        if (!response.ok) {
            throw new Error(`Erreur HTTP: ${response.status}`);
        }
        
        return await response.json();
    } catch (error) {
        throw error;
    }
}

// Fonction pour remplir les listes déroulantes
function populateDropdown(id, items) {
    const dropdown = document.getElementById(id);
    if (!dropdown) return;
    
    dropdown.innerHTML = '<option value="">Sélectionnez une option</option>';
    
    items.forEach(item => {
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = item.name;
        dropdown.appendChild(option);
    });
}

// Ajouter un champ de disque
function addDiskField() {
    diskCounter++;
    
    const diskContainer = document.getElementById('disks-container');
    const newDisk = document.createElement('div');
    newDisk.className = 'disk-entry';
    newDisk.innerHTML = `
        <h3>Disque ${diskCounter}</h3>
        <div class="form-group">
            <label for="disk-name-${diskCounter}">Nom du disque:</label>
            <input type="text" id="disk-name-${diskCounter}" name="disk_name_${diskCounter}" placeholder="Disque ${diskCounter}" required>
        </div>
        
        <div class="form-group">
            <label for="disk-size-${diskCounter}">Taille (GiB):</label>
            <input type="number" id="disk-size-${diskCounter}" name="disk_size_${diskCounter}" placeholder="Taille en GiB" min="1" value="10" required>
        </div>
        
        <div class="form-group">
            <label for="disk-description-${diskCounter}">Description:</label>
            <textarea id="disk-description-${diskCounter}" name="disk_description_${diskCounter}" placeholder="Description du disque" rows="2"></textarea>
        </div>
        <button type="button" class="remove-btn" onclick="removeDisk(this)">Supprimer ce disque</button>
    `;
    
    diskContainer.appendChild(newDisk);
}

// Supprimer un champ de disque
function removeDisk(button) {
    const diskEntry = button.parentNode;
    diskEntry.parentNode.removeChild(diskEntry);
}

// Ajouter un champ d'interface
function addInterfaceField() {
    interfaceCounter++;
    
    const interfacesContainer = document.getElementById('interfaces-container');
    const newInterface = document.createElement('div');
    newInterface.className = 'interface-entry';
    newInterface.innerHTML = `
        <h3>Interface ${interfaceCounter}</h3>
        <div class="form-group">
            <label for="interface-name-${interfaceCounter}">Nom de l'interface:</label>
            <input type="text" id="interface-name-${interfaceCounter}" name="interface_name_${interfaceCounter}" placeholder="Interface ${interfaceCounter}" required>
        </div>
        
        <div class="form-group">
            <label for="interface-ip-${interfaceCounter}">Adresse IP:</label>
            <input type="text" id="interface-ip-${interfaceCounter}" name="interface_ip_${interfaceCounter}" placeholder="ex: 192.168.1.1/24" required>
        </div>
        
        <div class="form-group">
            <label for="interface-description-${interfaceCounter}">Description de l'interface:</label>
            <textarea id="interface-description-${interfaceCounter}" name="interface_description_${interfaceCounter}" placeholder="Description de l'interface" rows="2"></textarea>
        </div>
        <button type="button" class="remove-btn" onclick="removeInterface(this)">Supprimer cette interface</button>
    `;
    
    interfacesContainer.appendChild(newInterface);
}

// Supprimer un champ d'interface
function removeInterface(button) {
    const interfaceEntry = button.parentNode;
    interfaceEntry.parentNode.removeChild(interfaceEntry);
}

// Passer à l'étape 3 (récapitulatif) avec vérification de l'existence du nom
async function goToStep3() {
    const vmName = document.getElementById('vm-name').value;
    
    // Utiliser la fonction validateVmName originale
    const vmNameValidation = validateVmName(vmName);
    if (!vmNameValidation.valid) {
        alert(vmNameValidation.message);
        return;
    }

    // Vérifier que tous les champs obligatoires sont remplis
    const vmDescription = document.getElementById('vm-description').value;
    const siteSelect = document.getElementById('device-site');
    const roleSelect = document.getElementById('device-role');
    const tenantSelect = document.getElementById('vm-tenant');
    const clusterSelect = document.getElementById('vm-cluster');
    const vmVcpus = document.getElementById('vm-vcpus').value;
    const vmMemory = document.getElementById('vm-memory').value;
    const statusSelect = document.getElementById('vm-status');
    
    if (!vmName || !siteSelect.value || !roleSelect.value || !clusterSelect.value || !tenantSelect.value || !statusSelect.value) {
        alert('Veuillez remplir tous les champs obligatoires (nom, status, site, rôle, cluster et tenant).');
        return;
    }
    
    // Vérifier si le nom existe déjà dans NetBox, seulement si nous avons une connexion
    if (netboxData.url && netboxData.token) {
        // Afficher un message de chargement
        const loadingMessage = document.createElement('div');
        loadingMessage.id = 'check-name-loading';
        loadingMessage.className = 'loading-message';
        loadingMessage.textContent = 'Vérification de la disponibilité du nom...';
        document.querySelector('.step-nav').before(loadingMessage);
        
        try {
            // Utiliser la fonction de vérification d'existence
            const existResult = await checkVmExists(vmName, netboxData.url, netboxData.token);
            
            // Supprimer le message de chargement
            document.getElementById('check-name-loading').remove();
            
            // Traiter le résultat
            if (existResult.error) {
                // En cas d'erreur, demander à l'utilisateur s'il souhaite continuer
                if (!confirm(existResult.message + "\n\nVoulez-vous continuer malgré tout?")) {
                    return;
                }
            } else if (existResult.exists) {
                // Si le nom existe déjà, afficher un message et s'arrêter
                alert(existResult.message);
                return;
            }
            // Si le nom n'existe pas, continuer normalement
        } catch (error) {
            // Supprimer le message de chargement en cas d'erreur
            if (document.getElementById('check-name-loading')) {
                document.getElementById('check-name-loading').remove();
            }
            
            console.error('Erreur lors de la vérification du nom:', error);
            // Demander à l'utilisateur s'il souhaite continuer
            if (!confirm("Une erreur est survenue lors de la vérification du nom. Voulez-vous continuer malgré tout?")) {
                return;
            }
        }
    }
    
    // Si tout est valide, préparer les données de la VM
    netboxData.vm = {
        name: vmName,
        description: vmDescription,
        site_id: siteSelect.value,
        role_id: roleSelect.value,
        tenant_id: tenantSelect.value,
        cluster_id: clusterSelect.value,
        vcpus: vmVcpus,
        memory: vmMemory,
        status: statusSelect.value,
        disks: [],
        interfaces: []
    };

    // Récupérer les disques
    document.querySelectorAll('.disk-entry').forEach(entry => {
        const nameInput = entry.querySelector('input[id^="disk-name-"]');
        const sizeInput = entry.querySelector('input[id^="disk-size-"]');
        const descInput = entry.querySelector('textarea[id^="disk-description-"]');
        
        if (nameInput && nameInput.value && sizeInput && sizeInput.value) {
            netboxData.vm.disks.push({
                name: nameInput.value,
                size: sizeInput.value,
                description: descInput ? descInput.value : ""
            });
        }
    });
    
    // Récupérer les interfaces
    document.querySelectorAll('.interface-entry').forEach(entry => {
        const nameInput = entry.querySelector('input[id^="interface-name-"]');
        const ipInput = entry.querySelector('input[id^="interface-ip-"]');
        const descInput = entry.querySelector('textarea[id^="interface-description-"]');
        
        if (nameInput && nameInput.value && ipInput && ipInput.value) {
            netboxData.vm.interfaces.push({
                name: nameInput.value,
                ip_address: ipInput.value,
                description: descInput ? descInput.value : ""
            });
        }
    });
    
    updateSummary();
    goToStep(3);
}

// Mettre à jour le récapitulatif
function updateSummary() {
    document.getElementById('summary-url').textContent = netboxData.url;
    document.getElementById('summary-name').textContent = netboxData.vm.name;
    
    const resourcesSummary = `${netboxData.vm.vcpus} vCPUs, ${netboxData.vm.memory} MiB`;
    document.getElementById('summary-resources').textContent = resourcesSummary;
    
    // Récapitulatif des disques
    const disksDiv = document.getElementById('summary-disks');
    disksDiv.innerHTML = '<h4>Disques virtuels:</h4>';
    
    if (netboxData.vm.disks.length > 0) {
        const disksList = document.createElement('ul');
        netboxData.vm.disks.forEach(disk => {
            const diskItem = document.createElement('li');
            diskItem.textContent = `${disk.name}: ${disk.size} GiB`;
            if (disk.description) {
                diskItem.textContent += ` (${disk.description})`;
            }
            disksList.appendChild(diskItem);
        });
        disksDiv.appendChild(disksList);
    } else {
        disksDiv.innerHTML += '<p>Aucun disque configuré</p>';
    }

    // Récapitulatif des interfaces
    const interfacesDiv = document.getElementById('summary-interfaces');
    interfacesDiv.innerHTML = '<h4>Interfaces réseau:</h4>';
    
    if (netboxData.vm.interfaces.length > 0) {
        const interfacesList = document.createElement('ul');
        netboxData.vm.interfaces.forEach(iface => {
            const interfaceItem = document.createElement('li');
            interfaceItem.textContent = `${iface.name}: ${iface.ip_address}`;
            if (iface.description) {
                interfaceItem.textContent += ` (${iface.description})`;
            }
            interfacesList.appendChild(interfaceItem);
        });
        interfacesDiv.appendChild(interfacesList);
    } else {
        interfacesDiv.innerHTML += '<p>Aucune interface configurée</p>';
    } 
}

// Créer la VM
function createVM() {
    document.getElementById('create-spinner').style.display = 'block';
    document.getElementById('create-result').style.display = 'none';
    document.getElementById('create-button').disabled = true;
    
    const apiData = {
        netbox_url: netboxData.url,
        api_token: netboxData.token,
        vm_name: netboxData.vm.name,
        description: netboxData.vm.description,
        site_id: netboxData.vm.site_id,
        role_id: netboxData.vm.role_id,
        tenant_id: netboxData.vm.tenant_id,
        cluster_id: netboxData.vm.cluster_id,
        vcpus: netboxData.vm.vcpus,
        memory: netboxData.vm.memory,
        status: netboxData.vm.status,
        disks: netboxData.vm.disks,
        interfaces: netboxData.vm.interfaces
    };
    
    callApi('create_vm', apiData)
    .then(response => {
        document.getElementById('create-spinner').style.display = 'none';
        
        const resultDiv = document.getElementById('create-result');
        resultDiv.style.display = 'block';
        
        if (response.success) {
            resultDiv.classList.remove('error');
            let disksInfo = '';
            
            // Vérifier si des disques ont été créés
            if (response.disks_results && Array.isArray(response.disks_results)) {
                const successDisks = response.disks_results.filter(disk => disk.success).length;
                const totalDisks = response.disks_results.length;
                
                if (totalDisks > 0) {
                    disksInfo = `<p>Disques créés: ${successDisks}/${totalDisks}</p>`;
                }
            }
            
            resultDiv.innerHTML = `
            <h3>VM créée avec succès</h3>
            <p>La VM "${netboxData.vm.name}" a été créée dans netbox.</p>
            <p>URL de la VM: <a href="${netboxData.url}/virtualization/virtual-machines/${response.data.id}" target="_blank">${netboxData.url}/virtualization/virtual-machines/${response.data.id}</a></p>
            ${disksInfo}
        `;
            
            document.getElementById('create-button').disabled = false;
            document.getElementById('create-button').textContent = 'Créer une autre VM';
            document.getElementById('create-button').removeEventListener('click', createVM);
            document.getElementById('create-button').addEventListener('click', resetForm);
        } else {
            resultDiv.classList.add('error');
            const requestData = response.request_data ? `<pre>${JSON.stringify(response.request_data, null, 2)}</pre>` : '';
            const responseData = response.data ? `<pre>${JSON.stringify(response.data, null, 2)}</pre>` : '';        
            resultDiv.innerHTML = `
                <h3>Erreur lors de la création</h3>
                <p>${response.message || 'Une erreur s\'est produite'}</p>
                <details>
                    <summary>Voir les détails techniques</summary>
                    <h4>Données de requête:</h4>
                    ${requestData}
                    <h4>Données de réponse:</h4>
                    ${responseData}
                </details>
            `;
    
            
            document.getElementById('create-button').disabled = false;
        }
    })
    .catch(error => {
        document.getElementById('create-spinner').style.display = 'none';
        document.getElementById('create-button').disabled = false;
        
        const resultDiv = document.getElementById('create-result');
        resultDiv.style.display = 'block';
        resultDiv.classList.add('error');
        resultDiv.innerHTML = `
        <h3>Erreur de communication</h3>
        <p>Impossible de communiquer avec le serveur.</p>
        <p>Détails: ${error.message}</p>
        <p>Stack: ${error.stack || 'Non disponible'}</p>
    `;
    });
}

// Naviguer entre les étapes
function goToStep(step) {
    document.querySelectorAll('.step').forEach(el => {
        el.classList.remove('active');
    });
    
    document.getElementById(`step-${step}`).classList.add('active');
    updateStepIndicators(step);
}

// Mettre à jour les indicateurs d'étape
function updateStepIndicators(currentStep) {
    for (let i = 1; i <= 3; i++) {
        const dot = document.getElementById(`dot-${i}`);
        if (!dot) continue;
        
        if (i < currentStep) {
            dot.classList.add('completed');
            dot.classList.remove('active');
        } else if (i === currentStep) {
            dot.classList.add('active');
            dot.classList.remove('completed');
        } else {
            dot.classList.remove('active', 'completed');
        }
    }
    
    const progress = document.getElementById('step-progress');
    if (progress) {
        progress.style.width = ((currentStep - 1) / 2 * 100) + '%';
    }
}

// Réinitialiser le formulaire pour créer une autre VM
function resetForm() {
    document.getElementById('vm-name').value = '';
    document.getElementById('vm-description').value = '';
    document.getElementById('device-role').selectedIndex = 0;
    document.getElementById('device-site').selectedIndex = 0;
    document.getElementById('vm-tenant').selectedIndex = 0;
    document.getElementById('vm-cluster').selectedIndex = 0;
    document.getElementById('vm-vcpus').value = '';
    document.getElementById('vm-memory').value = '';
    document.getElementById('vm-status').selectedIndex = 0;
    
    // Réinitialiser les disques
    document.getElementById('disks-container').innerHTML = `
        <div class="disk-entry">
            <h3>Disque 1</h3>
            <div class="form-group">
                <label for="disk-name-1">Nom du disque:</label>
                <input type="text" id="disk-name-1" name="disk_name_1" placeholder="Disque 1" required>
            </div>
            
            <div class="form-group">
                <label for="disk-size-1">Taille (GiB):</label>
                <input type="number" id="disk-size-1" name="disk_size_1" placeholder="Taille en GiB" min="1" value="10" required>
            </div>
            
            <div class="form-group">
                <label for="disk-description-1">Description:</label>
                <textarea id="disk-description-1" name="disk_description_1" placeholder="Description du disque" rows="2"></textarea>
            </div>
        </div>
    `;
    diskCounter = 1;

    // Réinitialiser les interfaces
    document.getElementById('interfaces-container').innerHTML = `
        <div class="interface-entry">
            <h3>Interface 1</h3>
            <div class="form-group">
                <label for="interface-name-1">Nom de l'interface:</label>
                <input type="text" id="interface-name-1" name="interface_name_1" placeholder="Interface 1" required>
            </div>
            
            <div class="form-group">
                <label for="interface-ip-1">Adresse IP:</label>
                <input type="text" id="interface-ip-1" name="interface_ip_1" placeholder="ex: 192.168.1.1/24" required>
            </div>
            
            <div class="form-group">
                <label for="interface-description-1">Description de l'interface:</label>
                <textarea id="interface-description-1" name="interface_description_1" placeholder="Description de l'interface" rows="2"></textarea>
            </div>
        </div>
    `;
    interfaceCounter = 1;
    
    document.getElementById('create-result').style.display = 'none';
    document.getElementById('create-button').textContent = 'Créer la VM';
    document.getElementById('create-button').removeEventListener('click', resetForm);
    document.getElementById('create-button').addEventListener('click', createVM);
    
    // Retourner à l'étape de configuration
    goToStep(2);
}