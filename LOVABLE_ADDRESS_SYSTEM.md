# Instructions pour Lovable - Système d'Adresses Complet

## 📋 Vue d'ensemble
Intégrer le système d'adresses avec autocomplete et validation automatique au backend. Le système fonctionne pour les utilisateurs connectés ET les clients guests.

---

## 🔌 Endpoints Disponibles

### Pour les Utilisateurs Connectés

#### 1. Autocomplete d'adresses (PUBLIC)
```
GET /api/address/autocomplete?q=Paris&country=FR&limit=6
```
**Réponse:**
```json
{
  "source": "adresse.data.gouv.fr",
  "results": [
    {
      "id": null,
      "label": "Paris",
      "postcode": "75001",
      "city": "Paris",
      "lat": 48.859,
      "lon": 2.347
    }
  ]
}
```

#### 2. Créer une adresse (POST)
```
POST /api/addresses
Authorization: Bearer {JWT_TOKEN}
Content-Type: application/ld+json

{
  "streetAddress": "1 Rue de la Paix",
  "postalCode": "75001",
  "city": "Paris",
  "country": "FR",
  "addressKind": "personal",
  "civility": "M",
  "firstName": "Jean",
  "lastName": "Dupont",
  "phone": "+33612345678",
  "label": "Adresse principale"
}
```

**Réponse Succès (201 Created):**
```json
{
  "@id": "/api/addresses/123",
  "id": 123,
  "streetAddress": "1 Rue de la Paix",
  "postalCode": "75001",
  "city": "Paris",
  "country": "FR",
  "latitude": 48.859,
  "longitude": 2.347,
  "addressKind": "personal"
}
```

**Réponse Erreur - Adresse invalide (400 Bad Request):**
```json
{
  "@context": "/api/contexts/Error",
  "@type": "hydra:Error",
  "hydra:title": "An error occurred",
  "hydra:description": "Invalid address: Address not found in official database",
  "detail": "Invalid address: Address not found in official database",
  "status": 400
}
```

#### 3. Modifier une adresse (PATCH)
```
PATCH /api/addresses/{id}
Authorization: Bearer {JWT_TOKEN}
Content-Type: application/ld+json

{
  "streetAddress": "2 Rue de la Paix",
  "postalCode": "75002"
}
```

**Même système de validation que POST** - Si adresse invalide → erreur 400

#### 4. Récupérer toutes les adresses (GET)
```
GET /api/addresses
Authorization: Bearer {JWT_TOKEN}
```

#### 5. Récupérer une adresse spécifique (GET)
```
GET /api/addresses/{id}
Authorization: Bearer {JWT_TOKEN}
```

#### 6. Supprimer une adresse (DELETE)
```
DELETE /api/addresses/{id}
Authorization: Bearer {JWT_TOKEN}
```

#### 7. Marquer comme adresse par défaut (PATCH)
```
PATCH /api/addresses/{id}/set-default
Authorization: Bearer {JWT_TOKEN}
```

---

### Pour les Clients Guests

#### 1. Autocomplete (même endpoint - PUBLIC)
```
GET /api/address/autocomplete?q=Paris&country=FR&limit=6
```

#### 2. Enregistrer l'adresse de livraison dans le panier guest
```
POST /guest/cart/address?guestToken={TOKEN}
Content-Type: application/ld+json

{
  "streetAddress": "1 Rue de la Paix",
  "postalCode": "75001",
  "city": "Paris",
  "country": "FR",
  "guestEmail": "client@example.com",
  "firstName": "Marie",
  "lastName": "Martin",
  "phone": "+33698765432"
}
```

**Réponse Succès (200 OK):**
```json
{
  "id": 456,
  "streetAddress": "1 Rue de la Paix",
  "postalCode": "75001",
  "city": "Paris",
  "country": "FR",
  "latitude": 48.859,
  "longitude": 2.347
}
```

**Réponse Erreur - Adresse invalide (400/422):**
```json
{
  "detail": "Invalid address: Address not found in official database"
}
```

---

## 🎯 Flux d'Intégration Frontend

### Pour Utilisateurs Connectés

#### 1. Formulaire de création/modification d'adresse
- **Champ "Rue" (streetAddress)** avec autocomplete temps réel
  - Appel GET `/api/address/autocomplete?q={query}&country=FR` au fur et à mesure de la saisie (debounce 300ms)
  - Afficher dropdown avec suggestions
  - Au clic: pré-remplir automatiquement:
    - `streetAddress` (champ rue)
    - `postalCode` (code postal)
    - `city` (ville)
    - Stocker `lat` et `lon` (invisible, pour la validation)

- **Autres champs:**
  - `civility` (M/Mme/Mlle)
  - `firstName` (prénom)
  - `lastName` (nom)
  - `phone` (téléphone)
  - `label` (ex: "Adresse principale")
  - `addressKind` (personal/business/relay)

#### 2. Validation automatique à la sauvegarde
**Le backend valide automatiquement lors du POST/PATCH**

- Au clic "Sauvegarder":
  - POST `/api/addresses` (si création) ou PATCH `/api/addresses/{id}` (si modification)
  - Headers: `Authorization: Bearer {JWT_TOKEN}`
  
- **Gestion des réponses:**
  - ✅ **HTTP 201/200**: Succès → Adresse sauvegardée, lat/lon ajoutées automatiquement
    - Rediriger vers liste des adresses
  
  - ❌ **HTTP 400/422**: Adresse invalide
    - Parser le message d'erreur du champ `detail`
    - Afficher message d'erreur en rouge: "Cette adresse n'existe pas..."
    - Garder le formulaire ouvert pour correction
  
  - 🔴 **Autres erreurs**: Afficher message générique

**Exemple de gestion d'erreur:**
```javascript
if (response.ok) {
  // Succès
  showSuccess("Adresse sauvegardée");
  navigateToAddressList();
} else if (response.status === 400 || response.status === 422) {
  const error = await response.json();
  showError(error.detail || "Erreur de validation");
} else {
  showError("Erreur serveur");
}
```

#### 3. Liste des adresses
- Afficher toutes les adresses: GET `/api/addresses`
- Pour chaque adresse:
  - Afficher l'adresse complète
  - Bouton "Modifier" → ouvre formulaire d'édition (PATCH)
  - Bouton "Supprimer" → DELETE `/api/addresses/{id}`
  - Bouton "Définir par défaut" → PATCH `/api/addresses/{id}/set-default`

---

### Pour Clients Guests

#### 1. Formulaire d'adresse de livraison pendant le checkout
- **Champ "Rue" avec autocomplete** (même que utilisateurs)
  - GET `/api/address/autocomplete?q={query}&country=FR`
  
- **Champs additionnels:**
  - `firstName`, `lastName`
  - `phone`
  - `guestEmail` (email du client)

#### 2. Sauvegarde de l'adresse au checkout
- Au clic "Continuer" ou "Passer la commande":
  - POST `/guest/cart/address?guestToken={GUEST_TOKEN}`
  - Headers: `Content-Type: application/ld+json`
  
- **Gestion des réponses:** (identique aux utilisateurs)
  - ✅ HTTP 200: Succès → Continuer vers paiement
  - ❌ HTTP 400/422: Erreur → Afficher message et bloquer

---

## 🛠️ Détails d'Implémentation

### Autocomplete Component
```javascript
// 1. Debounce sur champ street (300ms)
const handleStreetChange = debounce(async (value) => {
  if (value.length < 2) {
    setSuggestions([]);
    return;
  }
  
  const res = await fetch(
    `/api/address/autocomplete?q=${value}&country=FR&limit=6`
  );
  const data = await res.json();
  setSuggestions(data.results || []);
}, 300);

// 2. Au clic sur une suggestion
const handleSelectAddress = (suggestion) => {
  setFormData({
    ...formData,
    streetAddress: suggestion.label,
    postalCode: suggestion.postcode,
    city: suggestion.city,
    latitude: suggestion.lat,
    longitude: suggestion.lon
  });
  setSuggestions([]); // Fermer dropdown
};
```

### Erreurs à Afficher
- **"Cette adresse n'existe pas dans nos bases de données"** → Invite à corriger la rue, code postal ou ville
- **"Adresse non trouvée exactement"** → Adresse partiellement valide
- **"Erreur de validation"** → Problème serveur

### Points Clés
✅ L'autocomplete est PUBLIC (pas d'authentification nécessaire)
✅ La validation se fait AUTOMATIQUEMENT au save (POST/PATCH)
✅ Les coordonnées lat/lon sont retournées après validation
✅ La validation fonctionne pour POST (création) ET PATCH (modification)
✅ Les guests utilisent le même autocomplete + endpoint guest spécifique

---

## 📚 Récapitulatif des Endpoints à Intégrer

| Endpoint | Méthode | Auth | Utilisation |
|----------|---------|------|-------------|
| `/api/address/autocomplete` | GET | ❌ | Suggestions d'adresses (tous) |
| `/api/addresses` | POST | ✅ | Créer adresse (connecté) |
| `/api/addresses/{id}` | PATCH | ✅ | Modifier adresse (connecté) |
| `/api/addresses` | GET | ✅ | Lister adresses (connecté) |
| `/api/addresses/{id}` | GET | ✅ | Détail adresse (connecté) |
| `/api/addresses/{id}` | DELETE | ✅ | Supprimer adresse (connecté) |
| `/api/addresses/{id}/set-default` | PATCH | ✅ | Marquer défaut (connecté) |
| `/guest/cart/address` | POST | ❌ | Sauvegarder adresse guest |

---

## ✨ UX Recommandée

1. **Champ street avec autocomplete:** Afficher suggestions en temps réel
2. **Autres champs:** Auto-remplir après sélection autocomplete
3. **Validation:** Au save seulement (le backend rejette les invalides)
4. **Messages d'erreur:** Afficher en rouge, inviter à corriger
5. **Succès:** Toast/notification "Adresse sauvegardée", redirection
6. **Guests:** Même UX que connectés mais sans liste d'adresses (seulement checkout)

