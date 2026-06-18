# Delivery Pricing

## API

Estimate a delivery:

```http
POST /api/v1/deliveries/quote
Authorization: Bearer <mobile-token>
Content-Type: application/json
```

```json
{
  "departure": {
    "addressName": "Domicile",
    "userIdentifier": "USR_12345"
  },
  "destination": {
    "addressName": "Bureau",
    "userIdentifier": "USR_67890"
  },
  "serviceType": "STANDARD",
  "vehicleType": "MOTO"
}
```

`destination` may also be an address QR token:

```json
{
  "departure": {
    "addressName": "Domicile",
    "userIdentifier": "+33651896602"
  },
  "destination": "ADR_XYZ789ABC",
  "serviceType": "EXPRESS",
  "vehicleType": "MOTO"
}
```

Read selectable values:

```http
GET /api/v1/pricing/catalog
Authorization: Bearer <mobile-token>
```

## Response Shape

```json
{
  "recipient": {
    "id": "USR_67890",
    "firstName": "Mamadou",
    "lastName": "Diallo",
    "phone": "+224620123456"
  },
  "departure": {
    "latitude": 9.6412,
    "longitude": -13.5784
  },
  "destination": {
    "latitude": 9.69,
    "longitude": -13.52
  },
  "distanceKm": 7.4,
  "durationMinutes": 28,
  "serviceType": "STANDARD",
  "vehicleType": "MOTO",
  "pricing": {
    "distance": 7.4,
    "duration": 28,
    "base_price": 15000,
    "distance_price": 11100,
    "surcharges": [],
    "total_price": 26100,
    "currency": "GNF",
    "pricing_model_id": 1,
    "pricing_rule_id": 1
  },
  "deliveryCost": 26100,
  "currency": "GNF"
}
```

`deliveryCost` remains as a compatibility field for the Android UI. New screens should use `pricing.total_price`.

## Database-Driven Rules

Pricing is configured in:

- `pricing_models`
- `service_types`
- `vehicle_types`
- `zones`
- `pricing_rules`
- `pricing_surcharges`

Do not update existing rules in place. To change prices:

1. Create a new `pricing_models` row with a new `valid_from`.
2. Create the new `pricing_rules`.
3. Optionally set the previous model `valid_to`.

Deliveries should store `pricing_model_id`, `pricing_rule_id`, and the calculated price snapshot when a delivery table is introduced or extended.

## Android UI Contract

On the “Nouvelle livraison” screen:

1. Load `GET /api/v1/pricing/catalog`.
2. Let the user choose service type and vehicle type, defaulting to `STANDARD` and `MOTO`.
3. Once departure and destination are selected, call `POST /api/v1/deliveries/quote`.
4. Disable “Confirmer la livraison” while quote is loading or if quote failed.
5. Display:

```text
Distance : {distanceKm} km
Temps estime : {durationMinutes} min
Type de service : {serviceType}
Prix estime : {pricing.total_price} {pricing.currency}
```

