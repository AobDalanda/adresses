# Notifications mobiles

## Contrat backend

- Lister les notifications: `GET /api/v1/notifications`
- Lister uniquement les non lues: `GET /api/v1/notifications?unreadOnly=true`
- Marquer comme lue: `PUT /api/v1/notifications/{id}/read`
- Enregistrer le terminal: `PUT /api/v1/notifications/devices`

Le payload d'enregistrement doit toujours envoyer un `deviceId` stable par installation:

```json
{
  "token": "<fcm-token>",
  "platform": "android",
  "deviceId": "<stable-installation-id>"
}
```

Si FCM renouvelle le token, le frontend doit rappeler `PUT /api/v1/notifications/devices` avec le meme `deviceId`. Le backend desactive alors les anciens tokens actifs pour ce terminal.

## Traitement cote frontend

- Ne pas afficher une notification locale si son `notificationId` est deja present dans le stockage local.
- Utiliser `deliveryId` comme identifiant de remplacement pour les notifications de type `delivery_order.created`.
- Utiliser `collapseKey` comme cle de regroupement/remplacement quand la librairie mobile le permet.
- Apres ouverture d'une notification, appeler `PUT /api/v1/notifications/{notificationId}/read`, puis mettre a jour l'etat local.
- Pour le badge et l'inbox "nouveau", charger `GET /api/v1/notifications?unreadOnly=true`.
- Pour l'historique complet, charger `GET /api/v1/notifications` et afficher les elements avec `readAt !== null` comme deja lus.

Les push de livraison contiennent au minimum:

```json
{
  "type": "delivery_order.created",
  "notificationId": "<uuid>",
  "deliveryId": "<uuid>",
  "status": "QUOTED",
  "collapseKey": "delivery_order.<deliveryId>",
  "notificationGroup": "delivery_order"
}
```

Sur Android, utiliser un `notificationId` numerique stable derive de `deliveryId` pour que la nouvelle notification remplace l'ancienne. Sur iOS, utiliser le thread/groupement de la librairie de notification avec `collapseKey` ou `deliveryId`.
