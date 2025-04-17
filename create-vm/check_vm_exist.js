/**
 * Vérifie si une VM avec le nom spécifié existe déjà dans NetBox
 * @param {string} name - Le nom de la VM à vérifier
 * @param {string} netboxUrl - L'URL de l'API NetBox
 * @param {string} token - Le token d'API NetBox
 * @returns {Promise<Object>} - Résultat de la vérification (exists, message, error)
 */
export async function checkVmExists(name, netboxUrl, token) {
    // Vérifier que les paramètres sont valides
    if (!name || !netboxUrl || !token) {
        return {
            error: true,
            message: "Paramètres manquants pour vérifier l'existence de la VM."
        };
    }

    try {
        // Effectuer la requête à l'API NetBox
        const response = await fetch(`${netboxUrl}/api/virtualization/virtual-machines/?name=${encodeURIComponent(name)}`, {
            method: 'GET',
            headers: {
                'Authorization': `Token ${token}`,
                'Content-Type': 'application/json'
            }
        });

        // Vérifier si la requête a réussi
        if (!response.ok) {
            throw new Error(`Erreur ${response.status}: ${response.statusText}`);
        }

        // Analyser la réponse JSON
        const data = await response.json();
        
        // Vérifier si des résultats ont été trouvés
        if (data.count > 0) {
            return {
                exists: true,
                message: `Le nom "${name}" existe déjà dans NetBox. Veuillez choisir un autre nom.`
            };
        }

        // Aucun résultat trouvé, le nom est disponible
        return {
            exists: false,
            message: "Le nom est disponible."
        };
    } catch (error) {
        // Gérer les erreurs de requête ou de réseau
        console.error('Erreur lors de la vérification du nom dans NetBox:', error);
        return {
            error: true,
            message: `Impossible de vérifier si le nom existe déjà dans NetBox: ${error.message}`
        };
    }
}