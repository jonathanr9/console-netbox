// Fonction pour valider le nom de la VM
function validateVmName(name) {
    
    // Vérifier la longueur  
    if (name.length !== 9) {
        return {
            valid: false,
            message: "Le nom de la VM doit contenir exactement 9 caractères."
        };
    }
    
    if (!/^[a-z0-9]+$/.test(name)) {
        return {
            valid: false,
            message: "Erreur : Le nom de la VM doit contenir uniquement des lettres minuscules ou des chiffres."
        };
    }
    
    // 1) Vérifier caractère localisation 
    const C1 = name[0];
    if (!(C1 >= 'a' && C1 <= 'z' && C1 !== 'q' && C1 !== 'u' && C1 !== 'v' && C1 !== 'w' || C1 === '1'|| C1 === '3')) {
        return {
            valid: false,
            message: "Erreur : Le premier caractère '" + C1 + "' correspondant à la localisation est incorect."
        };
    }

    // 2) Vérifier caractère localisation complémentaire
    const C2 = name[1];
    if (!(C2 == 'e' || C2 == 'i' || C2 == 'l' || C2 == 'p' || C2 == 'r' || C2 == 's' || C2 === 'x')) {
        return {
            valid: false,
            message: "Erreur : Le second caractère '" + C2 + "' correspondant à la localisation complémentaire est incorect."
        };
    }

    // 3) Vérifier caractère fonction
    const C3 = name[2];
    if (C2 === 'x') {
        if (!['b', 'd', 'e', 'g', 'h', 'n', 'r', 's'].includes(C3)) {
            return {
                valid: false,
                message: "Erreur : Le troisième caractère '" + C3 + "'  correspondant à la fonction est incorect"
            };
        }
    } else {
        if (!['a', 'b', 'c', 'e', 'f', 'g', 'h', 'j', 'l', 'm','n', 'o', 'p', 'r', 's','t', 'v', 'w', 'x', 'y' ].includes(C3)) {  
            return {
                valid: false,
                message: "Erreur : Le troisième caractère '" + C3 + "'  correspondant à la fonction est incorect"
            };
        }
    }

    // 4) Vérifier caractère environnement
    const C4 = name[3];
    if (!(C4 == 'e' || C4 == 'p' || C4 == 'd' || C4 == 'm' || C4 == 'f' || C4 == 't')) {
        return {
            valid: false,
            message: "Erreur : Le quatirème caractère '" + C4 + "' correspondant à l'environnement est incorect."
        };
    }
    // Si toutes les vérifications passent  
    return {
        valid: true  
    };
}

export { validateVmName };