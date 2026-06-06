# Suivi GPS des livreurs

Toutes les routes sont versionnees sous `/api/v1` et exigent un JWT.

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
