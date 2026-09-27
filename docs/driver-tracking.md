# Suivi GPS des livreurs

Toutes les routes sont versionnees sous `/api/v1` et exigent un JWT.

## Consultation d'une offre

`GET /api/v1/deliveries/{deliveryId}/offer` est réservé au livreur authentifié.
Les détails ne sont sérialisés qu'après validation du compte actif, de
l'autorisation canonique issue du dossier et des documents, de la disponibilité
effective, d'une position GPS valide et récente et de la zone de départ. La
livraison doit être libre et au statut interne `QUOTED`. Ce statut est exposé
comme `PENDING` dans le contrat mobile. Les champs absents en base valent `null`.

La réponse `200` contient `id`, `reference`, `status`, `available`,
`packageType`, `description`, `contactPhone`, `pickupAddress`, `dropoffAddress`,
`recipient`, `pricing`, `distanceKm` et `durationMinutes`. `reference`,
`packageType` et `pricing.serviceFee` valent actuellement `null`, car le modèle
ne stocke pas ces notions. Aucune valeur synthétique n'est générée.

Codes : `401` session absente, `403` livreur non éligible, `404` livraison
inconnue et `409` livraison déjà attribuée ou plus disponible. Le corps du
`409` contient `available: false`.

## Acceptation d'une livraison

```http
POST /api/v1/deliveries/{deliveryId}/accept
Authorization: Bearer <jwt>
```

Le JWT doit appartenir a un livreur autorise. L'acceptation fait passer la
livraison de `QUOTED` a `ASSIGNED`, et l'API expose ce nouvel
etat sous le libelle `En cours`. La prise est atomique: si un autre livreur a
deja accepte la livraison, l'API renvoie `409 DELIVERY_ALREADY_ACCEPTED`.

```json
{
  "deliveryId": "01975aa9-df9c-7b25-b797-6b1ca912e68f",
  "driverId": 15,
  "status": "ASSIGNED",
  "statusLabel": "En cours",
  "statusGroup": "in_progress",
  "assignedAt": "2026-07-01 10:30:00+00"
}
```

## Envoi Android

```http
POST /api/v1/drivers/location
Authorization: Bearer <jwt>
Content-Type: application/json

{
  "driverId": 15,
  "latitude": 9.6412,
  "longitude": -13.5784,
  "accuracy": 5.3,
  "speed": 18.5,
  "heading": 220,
  "batteryLevel": 74,
  "source": "gps"
}
```

Le JWT doit appartenir au livreur `15`. Une reponse `201 {"success":true}` confirme
la persistance. Une panne Mercure est journalisee sans perdre la position GPS.

## Consultation

```http
GET /api/v1/drivers/15/location
GET /api/v1/drivers/15/locations?from=2026-06-01&to=2026-06-05&limit=100
Authorization: Bearer <jwt>
```

Un livreur ne consulte que ses donnees. Un JWT contenant `ROLE_ADMIN` peut
consulter tous les livreurs.

## Mercure et Leaflet

Topic: `driver/{driverId}/location`, par exemple `driver/15/location`.
Les evenements sont prives. Le navigateur doit d'abord demander a Symfony un
cookie HTTP-only autorisant uniquement ce topic.

```javascript
const driverId = 15;
const jwt = localStorage.getItem("jwt");

const authorizationResponse = await fetch(
    `/api/v1/drivers/${driverId}/mercure-authorization`,
    {
        method: "POST",
        headers: {
            Authorization: `Bearer ${jwt}`,
            Accept: "application/json"
        },
        credentials: "include"
    }
);

if (!authorizationResponse.ok) {
    throw new Error(`Autorisation Mercure refusee: ${authorizationResponse.status}`);
}

const authorization = await authorizationResponse.json();
const topic = authorization.topic;
const url = new URL(authorization.hubUrl, window.location.origin);
url.searchParams.append("topic", topic);

// Le cookie mercureAuthorization est envoye au hub. Il est HTTP-only:
// le JavaScript ne peut ni le lire ni l'exfiltrer.
const events = new EventSource(url, { withCredentials: true });

events.onmessage = ({ data }) => {
    const location = JSON.parse(data);
    marker.setLatLng([location.latitude, location.longitude]);
};

events.onerror = () => {
    console.error("Connexion Mercure interrompue");
};
```

Le payload contient `driverId`, `latitude`, `longitude`, `accuracy`, `speed`,
`heading` et `timestamp`.

Le cookie est signe avec `MERCURE_JWT_SECRET`. Cette valeur doit etre identique
au secret de validation configure dans le hub FrankenPHP/Mercure. En production,
l'API, le frontend et le hub doivent partager le meme site DNS afin que le cookie
puisse etre transmis; le hub doit aussi autoriser l'origine exacte du frontend
avec les credentials CORS. La directive Mercure `anonymous` ne doit pas etre
activee dans la configuration FrankenPHP/Caddy: sans cookie valide, le hub doit
refuser les abonnements aux evenements prives.

La specification complete est dans `docs/driver-tracking-openapi.yaml`.
# Disponibilité du livreur

Le choix en ligne/hors ligne est distinct de la fraîcheur de la position GPS.

```http
GET /api/v1/drivers/me/availability
Authorization: Bearer <JWT>
```

```http
PUT /api/v1/drivers/me/availability
Authorization: Bearer <JWT>
Content-Type: application/json

{"online": true}
```

La réponse contient `requestedOnline`, le choix explicite du livreur, et `effectiveOnline`, qui n'est vrai que si ce choix est actif, si le profil est autorisé et si une position GPS fiable a été reçue depuis moins de deux minutes. `online` reste temporairement présent comme alias de compatibilité de `requestedOnline`.

Pendant la période de compatibilité mobile, `availabilityVersion` est facultatif et ignoré lorsqu'il est envoyé. Le backend incrémente lui-même la version retournée à chaque écriture. Le contrôle optimiste fondé sur une version reçue sera activé ultérieurement, après coordination avec toutes les versions mobiles déployées.

La publication d'une position ne modifie jamais la disponibilité. Lorsque `requestedOnline` vaut `false`, la position est conservée à titre technique mais elle n'est pas publiée sur le canal temps réel et le prestataire est exclu des nouvelles attributions.
