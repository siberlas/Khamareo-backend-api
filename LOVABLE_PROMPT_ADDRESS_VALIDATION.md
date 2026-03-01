# Prompt Lovable - Intégration de la Validation d'Adresse

## Contexte
L'application a un nouveau système de validation d'adresses sur le backend. Quand un utilisateur essaie de sauvegarder une adresse, celle-ci est **automatiquement validée** contre une base de données officielle (API Adresse française ou Mapbox international).

**Important:** La validation se fait au moment de la sauvegarde, pas avant. Si l'adresse n'existe pas, la sauvegarde échouera avec une erreur HTTP 400 ou 422, et le frontend doit afficher ce message d'erreur à l'utilisateur.

## Endpoints Disponibles

### 1. Autocomplete d'adresses
```
GET /api/address/autocomplete?q=Paris&country=FR&limit=6
```

**Réponse:**
```json
{
  "source": "adresse|mapbox",
  "results": [
    {
      "id": "uuid",
      "street": "1 Rue de la Paix",
      "postalCode": "75001",
      "city": "Paris",
      "country": "FR",
      "lat": 48.8566,
      "lon": 2.3522
    }
  ],
  "cached": false
}
```

### 2. Reverse Geocoding (coordonnées → adresse)
```
GET /api/address/reverse-geocode?lat=48.8566&lon=2.3522
```

**Réponse:**
```json
{
  "source": "adresse|mapbox",
  "results": [
    {
      "street": "1 Rue de la Paix",
      "postalCode": "75001",
      "city": "Paris",
      "country": "FR"
    }
  ]
}
```

### 3. Sauvegarde d'adresse (POST /addresses)
La validation se fait **automatiquement** lors de la sauvegarde. Les réponses possibles sont :

**Cas 1 - Adresse valide (200 OK ou 201 Created):**
```json
{
  "id": 123,
  "street": "1 Rue de la Paix",
  "postalCode": "75001",
  "city": "Paris",
  "country": "FR",
  "lat": 48.8566,
  "lon": 2.3522
  // ... autres champs
}
```
→ Adresse acceptée et sauvegardée

**Cas 2 - Adresse invalide (400 Bad Request):**
```json
{
  "detail": "Invalid address: Address not found in official database",
  "status": 400,
  "title": "An error occurred"
}
```
→ L'adresse n'existe pas. Afficher erreur à l'utilisateur.

**Cas 3 - Adresse invalide avec suggestion (400 Bad Request):**
```json
{
  "detail": "Invalid address: Address not found, did you mean this one?",
  "status": 400,
  "title": "An error occurred"
}
```
→ L'adresse n'existe pas mais une suggestion est disponible. Afficher à l'utilisateur avec option de correction.

---

## Intégration Frontend

### Flow à implémenter

**Quand l'utilisateur remplit le formulaire d'adresse:**

1. **Autocomplete en temps réel** (champ street)
   - Appel GET `/api/address/autocomplete?q={query}&country={country}&limit=6`
   - Afficher liste de suggestions
   - Quand utilisateur clique sur une suggestion → remplir tous les champs (street, postal, city, lat, lon)

2. **Quand l'utilisateur clique sur "Sauvegarder":**
   - Envoyer les données au backend avec POST/PATCH vers `/addresses` ou `/addresses/{id}`
   - Le backend valide **automatiquement** l'adresse
   - Si validation échoue → erreur 400 → Afficher le message d'erreur à l'utilisateur
   - Si validation réussit → HTTP 200/201 → Adresse sauvegardée

3. **Gestion des erreurs de validation:**

| Statut HTTP | Message d'erreur | Action à faire |
|-------------|-----------------|-----------------|
| **200/201** | (aucun) | ✅ Adresse sauvegardée, rediriger/fermer le formulaire |
| **400** | "Address not found in official database" | ❌ Bloquer la sauvegarde, afficher "Cette adresse n'existe pas. Veuillez vérifier votre saisie." |
| **400** | "Address not found, did you mean this one?" | ⚠️ Proposer correction avec boutons "Accepter" / "Modifier" |
| **Other 400** | (autre message) | ❌ Afficher le message d'erreur tel quel |

### Pseudo-code du composant

### Pseudo-code du composant

```typescript
// State
address = {
  street: '',
  postalCode: '',
  city: '',
  country: 'FR',
  lat?: number,
  lon?: number
}
isSaving = false
errorMessage = ''

// Quand l'utilisateur change l'adresse
onAddressChange() {
  this.errorMessage = ''
}

// Quand l'utilisateur clique "Sauvegarder"
async submitForm() {
  this.isSaving = true
  this.errorMessage = ''
  
  try {
    // POST ou PATCH selon si c'est création ou édition
    const endpoint = this.address.id ? `/addresses/${this.address.id}` : '/addresses'
    const method = this.address.id ? 'PATCH' : 'POST'
    
    const response = await fetch(endpoint, {
      method: method,
      headers: { 'Content-Type': 'application/ld+json' },
      body: JSON.stringify({
        streetAddress: this.address.street,
        postalCode: this.address.postalCode,
        city: this.address.city,
        country: this.address.country,
        lat: this.address.lat,
        lon: this.address.lon,
        // ... autres champs Address (type, addressKind, etc.)
      })
    })

    if (response.ok) {
      // ✅ SUCCÈS - Adresse sauvegardée
      console.log('Address saved successfully')
      // Rediriger ou fermer le formulaire
      this.navigateToAddressList()
      return
    }

    // ❌ ERREUR DE VALIDATION
    if (response.status === 400 || response.status === 422) {
      const error = await response.json()
      const errorDetail = error.detail || error.message || 'Erreur inconnue'
      
      // Afficher le message d'erreur du backend
      this.errorMessage = errorDetail
      
      // Optionnel: parser l'erreur pour offrir une UX plus friendly
      if (errorDetail.includes('did you mean this one?')) {
        this.errorMessage = '⚠️ L\'adresse n\'a pas été trouvée exactement. Veuillez vérifier votre saisie.'
      } else if (errorDetail.includes('Address not found')) {
        this.errorMessage = '❌ Cette adresse n\'existe pas dans notre base de données. Veuillez corriger.'
      }
    } else {
      this.errorMessage = `Erreur serveur: ${response.status}`
    }
  } catch (error) {
    this.errorMessage = 'Impossible de sauvegarder. Veuillez réessayer.'
  }
  
  this.isSaving = false
}
```

### Composant UI à créer/modifier

**Modifier le formulaire `AddressForm`** pour:

1. **Affiche un champ d'input avec autocomplete** pour `streetAddress`
   - Appelle `/api/address/autocomplete` en temps réel pendant la saisie
   - Montre liste de suggestions avec street, postal, city
   - Au clic sur une suggestion: pré-remplir streetAddress, postalCode, city, lat, lon

2. **Affiche les autres champs** (postalCode, city, country)
   - Normalement des inputs éditables
   - Pré-remplir après autocomplete si l'utilisateur choisit une suggestion

3. **Affiche le bouton "Sauvegarder"** qui déclenche `submitForm()`
   - Spinner/disabled pendant la sauvegarde
   - Enabled par défaut

4. **Affiche les messages d'erreur** en rouge si présents
   - Ex: "Cette adresse n'existe pas..."
   - Ex: "Adresse introuvable, veuillez corriger..."
   - Message vient du backend dans la réponse 400

---

## Points Importants

✅ **À faire:**
- [ ] Ajouter autocomplete sur le champ `streetAddress` qui appelle `/api/address/autocomplete`
- [ ] Quand l'utilisateur sélectionne une suggestion, pré-remplir streetAddress, postalCode, city, lat, lon
- [ ] Au clic sur "Sauvegarder", envoyer POST/PATCH vers `/addresses` ou `/addresses/{id}`
- [ ] Si réponse HTTP 200/201: ✅ Fermer formulaire / rediriger
- [ ] Si réponse HTTP 400/422: ❌ Afficher le message d'erreur du backend en rouge
- [ ] Parser les messages d'erreur pour offrir UX plus friendly ("Adresse n'existe pas...", etc.)

❌ **À ne PAS faire:**
- ❌ Ne pas créer de bouton "Valider" séparé (validation se fait lors de la sauvegarde)
- ❌ Ne pas oublier les coordonnées lat/lon lors du POST/PATCH
- ❌ Ne pas ignorer les erreurs 400/422 de validation

## Exemple d'intégration dans un formulaire React

```typescript
// Dans le formulaire Address
const [addressData, setAddressData] = useState({
  streetAddress: '',
  postalCode: '',
  city: '',
  country: 'FR',
  lat: undefined,
  lon: undefined
})
const [isSaving, setIsSaving] = useState(false)
const [errorMessage, setErrorMessage] = useState('')
const [suggestions, setSuggestions] = useState([])

// Autocomplete en temps réel
const handleStreetChange = debounce(async (value) => {
  if (value.length < 3) {
    setSuggestions([])
    return
  }
  
  try {
    const res = await fetch(
      `/api/address/autocomplete?q=${value}&country=${addressData.country}&limit=6`
    )
    const data = await res.json()
    setSuggestions(data.results || [])
  } catch (e) {
    console.error('Autocomplete failed:', e)
  }
}, 300)

// Quand l'utilisateur sélectionne une suggestion
const handleSelectSuggestion = (suggestion) => {
  setAddressData({
    ...addressData,
    streetAddress: suggestion.street,
    postalCode: suggestion.postalCode,
    city: suggestion.city,
    lat: suggestion.lat,
    lon: suggestion.lon
  })
  setSuggestions([])
}

// Sauvegarder l'adresse
const handleSave = async () => {
  setIsSaving(true)
  setErrorMessage('')
  
  try {
    const method = addressData.id ? 'PATCH' : 'POST'
    const endpoint = addressData.id 
      ? `/addresses/${addressData.id}` 
      : '/addresses'
    
    const response = await fetch(endpoint, {
      method: method,
      headers: { 
        'Content-Type': 'application/ld+json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify(addressData)
    })

    if (response.ok) {
      // ✅ Succès
      toast.success('Adresse sauvegardée')
      navigate('/addresses')
      return
    }

    // ❌ Erreur de validation (400/422)
    if (response.status === 400 || response.status === 422) {
      const error = await response.json()
      let message = error.detail || error.message || 'Erreur inconnue'
      
      // Parser le message pour une meilleure UX
      if (message.includes('did you mean this one?')) {
        message = '⚠️ Cette adresse n\'a pas été trouvée exactement. Veuillez corriger ou vérifier votre saisie.'
      } else if (message.includes('Address not found')) {
        message = '❌ Cette adresse n\'existe pas dans nos bases de données. Veuillez corriger.'
      }
      
      setErrorMessage(message)
    } else {
      setErrorMessage(`Erreur serveur (${response.status})`)
    }
  } catch (error) {
    setErrorMessage('Impossible de sauvegarder. Veuillez réessayer.')
  }
  
  setIsSaving(false)
}

return (
  <form>
    {/* Champ Street avec autocomplete */}
    <div>
      <label>Rue *</label>
      <input
        type="text"
        value={addressData.streetAddress}
        onChange={(e) => {
          setAddressData({...addressData, streetAddress: e.target.value})
          setErrorMessage('')
          handleStreetChange(e.target.value)
        }}
        placeholder="Ex: 1 Rue de la Paix"
        required
      />
      
      {/* Liste de suggestions */}
      {suggestions.length > 0 && (
        <ul className="suggestions-list">
          {suggestions.map((sugg, idx) => (
            <li key={idx} onClick={() => handleSelectSuggestion(sugg)}>
              {sugg.street}, {sugg.postalCode} {sugg.city}
            </li>
          ))}
        </ul>
      )}
    </div>

    {/* Autres champs */}
    <input
      type="text"
      placeholder="Code postal"
      value={addressData.postalCode}
      onChange={(e) => setAddressData({...addressData, postalCode: e.target.value})}
      required
    />
    
    <input
      type="text"
      placeholder="Ville"
      value={addressData.city}
      onChange={(e) => setAddressData({...addressData, city: e.target.value})}
      required
    />
    
    {/* Message d'erreur */}
    {errorMessage && (
      <div className="alert alert-error" role="alert">
        {errorMessage}
      </div>
    )}

    {/* Bouton Sauvegarder */}
    <button 
      type="button" 
      onClick={handleSave}
      disabled={isSaving}
    >
      {isSaving ? 'Sauvegarde...' : 'Sauvegarder'}
    </button>
  </form>
)
```

---

## Tester l'intégration

### Tester la validation automatique lors de la sauvegarde

**Via curl - Adresse VALIDE:**

```bash
curl -X POST http://localhost:8000/addresses \
  -H "Content-Type: application/ld+json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "streetAddress": "1 Rue de la Paix",
    "postalCode": "75001",
    "city": "Paris",
    "country": "FR",
    "addressKind": "personal",
    "type": "address"
  }'
```

**Réponse attendue (201 Created):**
```json
{
  "id": 123,
  "streetAddress": "1 Rue de la Paix",
  "postalCode": "75001",
  "city": "Paris",
  "lat": 48.8566,
  "lon": 2.3522,
  "@context": "/api/contexts/Address"
}
```

---

**Via curl - Adresse INVALIDE:**

```bash
curl -X POST http://localhost:8000/addresses \
  -H "Content-Type: application/ld+json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "streetAddress": "XXX rue inexistante 12345",
    "postalCode": "75001",
    "city": "Paris",
    "country": "FR",
    "addressKind": "personal",
    "type": "address"
  }'
```

**Réponse attendue (400 Bad Request):**
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

Le frontend affichera: `"❌ Cette adresse n'existe pas dans notre base de données. Veuillez corriger."`

