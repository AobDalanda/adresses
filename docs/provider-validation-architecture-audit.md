# Audit d'architecture - Validation des comptes Prestataires

Date de l'audit : 9 juin 2026

## 1. Perimetre et methode

L'audit porte sur l'etat courant du workspace Symfony, migrations appliquees et donnees
presentes dans la base de developpement Docker. Il couvre le code, les routes effectives,
le schema PostgreSQL, les tests et la documentation disponible.

Limites :

- aucun projet Android, fichier Kotlin, Gradle ou `AndroidManifest.xml` n'est present sous
  `/home/bsidy/DEV/adressage-dev`;
- les composants Prestataire (`ProviderProfile`, services, endpoints et migrations recentes)
  sont actuellement non suivis par Git, tandis que plusieurs fichiers d'authentification sont
  modifies sans commit. L'audit decrit donc le workspace, pas une version de reference stable;
- la base inspectee est la base Docker `adressage`, pas une extraction de production.

## 2. Synthese executive

Le backend contient les briques initiales d'un profil Prestataire, mais pas encore un workflow
de validation complet. Deux modeles paralleles representent le meme processus :

1. `provider_profile` porte les activites et le statut d'autorisation;
2. `driver_application` et ses tables filles portent le dossier, le vehicule et les documents.

Ces modeles ne sont pas relies par une cle et leurs statuts ne sont pas synchronises. La
validation administrative modifie uniquement `provider_profile`; le dossier
`driver_application` reste `PENDING`. Il n'existe ni verification automatique, ni demande de
correction, ni motif de decision, ni historique de transitions.

Le principal risque de securite est la gestion manuelle des JWT dans chaque controleur. Le
firewall Symfony ne protege pas les routes API. Les roles administratifs sont lus depuis les
claims JWT, mais aucun flux de connexion fourni par l'application n'emet `ROLE_ADMIN`.

Le principal risque API est la coexistence de controleurs Symfony classiques et de ressources
API Platform sur les memes chemins. Les routes effectives dependent de l'ordre de chargement.
L'OpenAPI genere ne decrit ni les vrais payloads, ni les vraies reponses, ni la securite.

Conclusion : une refonte brutale serait risquee. Il faut d'abord stabiliser les contrats,
introduire une source de verite pour le statut et synchroniser l'ancien modele par compatibilite.

## 3. Architecture globale

### 3.1 Organisation

Le projet est un monolithe Symfony 7.4 avec :

- `src/Entity` : entites Doctrine, dont `UserAccount` et `ProviderProfile`;
- `src/Api/Resource` : declaration des operations API Platform;
- `src/Api/Controller` : controleurs invocables, souvent avec parsing JSON manuel;
- `src/Controller` : controleurs Symfony historiques, parfois sur les memes routes;
- `src/Service` : services applicatifs et acces SQL DBAL;
- `src/Repository` : repositories Doctrine;
- `src/Security` : securite du suivi GPS uniquement;
- `migrations` : schema PostgreSQL et PostGIS.

L'architecture est hybride :

- ORM pour une partie du modele;
- SQL DBAL natif pour les parcours principaux;
- API Platform utilise comme routeur/documenteur, avec `deserialize: false` et `output: false`;
- logique metier, validation, mapping et transaction souvent regroupes dans les controleurs.

Ce n'est pas une architecture DDD effective. Il n'existe pas de module/bounded context
Prestataire explicite, d'agregat de candidature, de machine a etats ou d'evenements metier.

### 3.2 Couche metier

Forces :

- transactions DBAL sur l'inscription detaillee;
- contraintes SQL sur les activites, statuts, types de vehicule et unicites documentaires;
- separation partielle entre controleurs et services;
- controle d'autorisation du suivi GPS pour un livreur approuve.

Faiblesses :

- `ProviderProfileService` manipule directement des tableaux et du SQL;
- `DriverRegistrationService` persiste un second modele sans coordination de statut;
- les entites ORM sont anemiques et incompletes;
- les invariants sont dupliques entre controleurs, services et contraintes SQL;
- les chaines de statut remplacent des enums/types metier;
- aucune transition n'exprime qui peut faire quoi, depuis quel etat et avec quel motif.

### 3.3 Couche API

Deux piles coexistent :

- controleurs classiques avec attributs `#[Route]`;
- ressources API Platform avec controleurs custom.

Des collisions sont confirmees :

- `POST /api/v1/auth/otp/request` execute actuellement `AuthController::requestOtp`;
- `POST /api/v1/auth/otp/verify` execute actuellement `AuthController::verifyOtp`;
- `PATCH /api/v1/user/me` execute actuellement `UserProfileUpdateAction`;
- `POST /api/v1/auth/client/login` execute le controleur classique;
- `GET /api/v1/subscription-plans` execute la ressource API Platform.

Le comportement effectif n'est donc pas deduisible uniquement depuis les ressources API.

### 3.4 Securite et roles

Le firewall principal utilise un provider en memoire vide et aucune regle `access_control`.
L'authentification est repetee dans les controleurs via `JwtAuthService::decodeFromRequest()`.

Claims mobiles :

```json
{
  "sub": "telephone",
  "typ": "mobile",
  "uid": 123,
  "tv": 2,
  "iat": 0,
  "exp": 0
}
```

Le `token_version` invalide les anciennes sessions lors d'une nouvelle verification OTP.
Un refresh token de 30 jours est maintenant emis avec `typ=mobile_refresh`.

Incoherences :

- les API admin exigent `roles: ["ROLE_ADMIN"]`;
- les flux OTP et client ne chargent jamais `account_type=admin` et n'emettent aucun role;
- un compte `admin` en base ne devient donc pas administrateur via les flux fournis;
- chaque controleur choisit entre 401 et 403 sans politique commune;
- l'upload de documents n'exige aucune authentification;
- la securite OpenAPI est vide (`security: []`, aucun `securityScheme`);
- aucun rate limiting ni verrouillage n'est applique aux essais OTP invalides.

## 4. Modele de donnees

### 4.1 UserAccount

`user_account` centralise :

- identite et contact;
- verification du telephone;
- type de compte (`client`, `provider`, `admin`);
- chemins de photo, piece d'identite et permis;
- numero de piece d'identite;
- version de session JWT.

Problemes :

- les documents Prestataire sont portes a la fois par `user_account` et les tables du dossier;
- `account_type` est une chaine libre dans l'entite PHP, contrainte uniquement en base;
- `verified` signifie verification OTP, pas validation Prestataire, mais les noms peuvent etre
  confondus;
- les setters/getters ORM sont incomplets pour plusieurs champs;
- l'association inverse `providerProfile` n'est pas maintenue par des methodes de domaine.

### 4.2 ProviderProfile

Champs :

- `user_id` unique et cascade;
- `can_deliver`;
- `can_transport_people`;
- `validation_status`;
- dates de creation et mise a jour.

Statuts : `pending`, `approved`, `rejected`, `suspended`.

La contrainte impose au moins une activite. Le profil ne contient toutefois ni dossier courant,
ni motif, ni auteur de decision, ni date de soumission/validation/suspension, ni version.

### 4.3 DriverApplication et documents

Le dossier detaille est constitue de tables SQL sans entites ORM :

- `driver_application`;
- `driver_vehicle`;
- `driver_license`;
- `driver_vehicle_document`;
- `driver_vehicle_photo`;
- `driver_delivery_zone`.

Statuts : `PENDING`, `APPROVED`, `REJECTED`, `SUSPENDED`.

Constats :

- `driver_application.user_id` est nullable;
- aucun index unique ne garantit un seul dossier actif par utilisateur;
- aucun lien n'existe entre `provider_profile` et `driver_application`;
- aucune version de document, date de verification ou resultat automatique n'est stocke;
- aucun type/metadonnees de piece d'identite n'est stocke dans une table document generique;
- les chemins fournis a l'inscription ne sont pas verifies comme appartenant a l'appelant.

Dans la base auditee :

- 1 profil Prestataire `pending`, transport de personnes uniquement;
- 4 dossiers `PENDING` : 1 `BOTH`, 3 `TRANSPORTEUR`;
- 3 dossiers n'ont aucun `provider_profile`;
- aucun utilisateur n'a plusieurs dossiers lies, mais le schema l'autorise;
- toutes les migrations disponibles sont appliquees.

### 4.4 Enumerations et contraintes

Des enums PHP existent pour les abonnements et paiements, mais aucun enum Prestataire.
Les valeurs Prestataire sont dupliquees dans :

- constantes de l'entite;
- tableau `ProviderProfileService::STATUSES`;
- constantes du controleur d'inscription;
- contraintes SQL;
- logique de compatibilite des anciens `account_type`.

Les deux conventions de casse (`pending` et `PENDING`) augmentent le risque de divergence.

### 4.5 Audit

La table generique `audit_log` contient seulement acteur, action, cible, IP et date.
L'inscription detaillee journalise `SUBMIT_DRIVER_APPLICATION`.

Les actions d'approbation, rejet, suspension et modification d'activites ne sont pas auditees.
L'ancien et le nouvel etat, le motif et l'administrateur ne sont pas conserves.

## 5. API existantes et contrats

### 5.1 Inscription et authentification

`POST /api/v1/auth/register`

- pre-inscription client ou ancien `accountType=livreur`;
- JSON ou multipart;
- stocke `pending_user_registration`;
- demande un OTP;
- reponse `202 {"message":"OTP envoye"}`.

`POST /api/v1/auth/register/verify`

- payload : `{"phone":"...","otp":"..."}`;
- cree/met a jour `user_account`;
- cree eventuellement un `provider_profile` pour l'ancien type livreur;
- initialise l'abonnement gratuit;
- reponse 201 avec `token`, `refreshToken`, `user`;
- ne cree pas de `driver_application`.

`POST /api/v1/auth/otp/request`

- payload : `{"phone":"..."}`;
- exige un utilisateur deja verifie;
- ne permet donc pas seul l'OTP initial d'un nouveau Prestataire.

`POST /api/v1/auth/otp/verify`

- authentifie un compte existant;
- retourne les tokens et l'utilisateur;
- la route effective est le controleur historique.

`POST /api/v1/auth/refresh-token`

- payload : `{"refreshToken":"..."}`;
- reemet un access token et un refresh token;
- ne fait pas de rotation serveur individuelle : les deux restent valides tant que la version
  globale du compte n'est pas modifiee.

### 5.2 Inscription Prestataire detaillee

`POST /api/v1/user/register/driver`

Payload principal :

```json
{
  "phone": "33600000000",
  "otp": "123456",
  "profile": {
    "signupAs": "LIVREUR|TRANSPORTEUR|BOTH",
    "fullName": "Nom",
    "email": "nom@example.com",
    "identityDocumentNumber": "CNI-123",
    "identityDocumentPath": "chemin"
  },
  "vehicle": {
    "type": "MOTO|VOITURE|VELO|A_PIED",
    "brand": "Marque",
    "model": "Modele",
    "licensePlate": "AB-123",
    "deliveryZones": ["Conakry"]
  },
  "driverLicense": {
    "number": "PERMIS-1",
    "category": "A",
    "expiryDate": "2030-01-01",
    "photoPath": "chemin"
  },
  "vehicleDocuments": {
    "insurancePath": "chemin",
    "registrationPath": "chemin",
    "registrationFrontPath": "chemin",
    "registrationBackPath": "chemin"
  },
  "vehiclePhotoPaths": ["chemin"]
}
```

Reponse 201 :

```json
{
  "token": "...",
  "refreshToken": "...",
  "user": {},
  "application": {
    "applicationId": 1,
    "status": "PENDING"
  }
}
```

Le controleur :

1. valide manuellement le JSON;
2. consomme l'OTP avant la transaction;
3. upsert le compte comme `provider`;
4. cree/reinitialise `provider_profile` a `pending`;
5. initialise l'abonnement;
6. cree un nouveau `driver_application`;
7. retourne une session utilisable.

Un echec SQL apres verification consomme l'OTP. Le retry exige alors un nouvel OTP.

### 5.3 Upload

`POST /api/v1/uploads`, multipart `category` + `file`.

Categories : identite, permis, assurance, immatriculation, photos vehicule/profil.
La validation reelle inspecte le MIME et le contenu, mais :

- l'endpoint est public;
- la limite annoncee par `UploadStorageService` est 10 Mo, puis le validateur interne impose
  5 Mo;
- le chemin retourne n'est lie ni a une session de candidature ni a un proprietaire;
- l'inscription accepte tout chemin syntaxiquement valide de moins de 255 caracteres.

### 5.4 Profil Prestataire

`GET /api/v1/provider/profile`

- JWT mobile requis;
- retourne uniquement activites, statut et identite de base;
- ne retourne ni dossier, ni documents, ni vehicule, ni motif.

`PATCH /api/v1/provider/profile`

```json
{
  "canDeliver": true,
  "canTransportPeople": false
}
```

Toute modification remet le profil a `pending`, y compris un profil suspendu ou rejete, sans
regle de transition, sans nouveau dossier et sans audit.

### 5.5 Administration

`GET /api/v1/admin/providers`

- filtres : `status`, `canDeliver`, `canTransportPeople`;
- pas de pagination;
- retourne tous les profils en memoire.

`GET /api/v1/admin/providers/{profileId}`

- l'identifiant est celui du profil, pas celui de l'utilisateur ou du dossier;
- le detail ne contient pas les documents ni le dossier de verification.

`PATCH /api/v1/admin/providers/{profileId}/status`

```json
{
  "validationStatus": "pending|approved|rejected|suspended"
}
```

Toutes les transitions sont autorisees. Il n'y a pas de motif, commentaire, auteur, concurrence
optimiste, notification ou synchronisation de `driver_application.status`.

### 5.6 Validation et documentation

La plupart des endpoints Prestataire utilisent des tableaux et validations manuelles. Le
Validator Symfony est utilise pour le tracking et certains abonnements, pas pour l'inscription
Prestataire.

L'OpenAPI genere est incorrect pour ces operations :

- schemas vides;
- reponses documentees en 204 alors que le code retourne 200/201;
- payload de statut admin associe par erreur au schema `DriverTracking`;
- aucune authentification Bearer documentee.

## 6. Android

Le code Android n'est pas present dans le perimetre accessible. Il est impossible de confirmer :

- les Activities/Fragments/Composables;
- les modeles Kotlin et DTO Retrofit;
- le stockage des tokens;
- les ViewModels et repositories;
- les guards de navigation;
- le traitement des statuts Prestataire.

Impacts certains deduits des contrats backend :

- Android doit distinguer `user.verified` de `providerProfile.validationStatus`;
- il doit appeler `GET /provider/profile` apres connexion, car les reponses d'authentification
  n'incluent pas systematiquement le profil Prestataire;
- les statuts actuels ne permettent pas d'afficher "correction demandee";
- la soumission est non idempotente et peut creer plusieurs dossiers;
- la reprise apres erreur 500 est fragile car l'OTP peut deja etre consomme;
- les erreurs sont des messages libres sans code metier stable;
- le refresh token existe dans le workspace courant mais doit etre confirme dans les DTO et
  l'intercepteur Android.

Un second audit dans le depot Android est obligatoire avant toute modification de navigation.

## 7. Workflow reellement implemente

### Parcours A - ancien livreur

1. `POST /auth/register` avec `accountType=livreur` et documents multipart.
2. Creation/mise a jour de `pending_user_registration`.
3. Envoi OTP.
4. `POST /auth/register/verify`.
5. Creation de `user_account(provider)`.
6. Creation de `provider_profile(canDeliver=true, pending)`.
7. Aucun `driver_application` detaille.

### Parcours B - inscription detaillee

1. Upload public des fichiers.
2. Obtention d'un OTP par un parcours externe ou prealable non formalise.
3. `POST /user/register/driver`.
4. Verification OTP.
5. Creation/mise a jour du compte en `provider`.
6. Creation/reinitialisation du profil a `pending`.
7. Creation du dossier detaille a `PENDING`.
8. Retour immediat d'un JWT.

### Administration

1. Liste des `provider_profile`.
2. Consultation du meme niveau de detail.
3. Mise a jour libre du statut du profil.
4. Le dossier detaille, les documents et l'audit ne sont pas affectes.

### Activation operationnelle

Seul le suivi GPS implemente une regle explicite : un utilisateur `provider` peut publier sa
position uniquement si `canDeliver=true` et `validationStatus=approved`.

Les autres capacites futures devront reproduire cette verification si aucune politique centrale
n'est introduite.

### Etats implicites

- compte OTP verifie mais sans profil Prestataire;
- profil Prestataire sans dossier detaille;
- dossier detaille sans profil Prestataire;
- profil `approved` avec dossier `PENDING`;
- profil remis `pending` apres changement d'activites, avec ancien dossier inchange;
- compte `provider` suspendu qui reste connecte et conserve les fonctions non protegees;
- fichiers uploades mais jamais rattaches a un dossier;
- pre-inscription `VERIFIED` conservee apres creation du compte.

Etapes cibles absentes :

- verification automatique;
- revue administrative structuree;
- demande de correction;
- resoumission versionnee;
- motifs de rejet/suspension;
- historique complet;
- notification de decision.

## 8. Forces de l'existant

- migrations versionnees et appliquees;
- contraintes de base utiles sur les activites et les documents uniques;
- compatibilite prevue pour plusieurs anciens `account_type`;
- transaction autour de la creation du compte, profil et dossier;
- stockage externe avec inspection MIME/contenu;
- token version pour invalider les sessions;
- tests sur l'authentification minimale, les activites et le tracking;
- regle operationnelle correcte pour le tracking d'un livreur approuve;
- index sur statut et activites du profil.

## 9. Faiblesses, incoherences et dette

### Critiques

1. Deux sources de verite pour le statut, sans synchronisation.
2. Administration impossible via les flux normaux, faute d'emission de `ROLE_ADMIN`.
3. Upload public et chemins de documents non lies a l'utilisateur/dossier.
4. Aucune machine a etats : transitions arbitraires et non auditees.
5. Routes et implementations dupliquees, comportement dependant de l'ordre.

### Elevees

1. Absence de correction demandee et de versions de dossier/documents.
2. Aucun detail documentaire dans l'API admin.
3. OTP consomme avant la transaction d'inscription.
4. Plusieurs parcours d'inscription produisent des donnees differentes.
5. OpenAPI inutilisable comme contrat Android.
6. Pas d'idempotence ni d'unicite d'un dossier actif par utilisateur.
7. Suspension non centralisee dans les autorisations.

### Moyennes

1. Validation manuelle et messages libres.
2. Statuts en chaines et casses differentes.
3. Entites ORM partielles alors que le metier utilise DBAL.
4. Liste admin sans pagination.
5. Refresh token sans rotation/revocation par session.
6. Tests insuffisants et suite actuellement rouge : 7 erreurs et 2 echecs preexistants.
7. Limites d'upload contradictoires (10 Mo puis 5 Mo).

## 10. Risques de regression

### Backend et donnees

- choisir `driver_application` comme source de verite sans backfill casserait les profils issus
  de l'ancien parcours;
- choisir `provider_profile` ferait perdre le detail et l'historique des dossiers;
- rendre `driver_application.user_id` obligatoire echouerait sur les trois dossiers orphelins;
- ajouter une unicite par utilisateur echouerait si des doublons apparaissent avant migration;
- renommer directement les statuts casserait Android et les filtres admin;
- modifier la semantique de `account_type` invaliderait le tracking et les sessions existantes.

### API

- supprimer brutalement les routes historiques peut casser une version Android deja publiee;
- changer les reponses d'authentification ou les codes HTTP peut casser les parseurs Retrofit;
- imposer l'authentification sur l'upload necessite un mecanisme de session de pre-inscription.

### Android

- une navigation fondee seulement sur `accountType=provider` donnerait acces avant approbation;
- une enum Kotlin non tolerante aux nouvelles valeurs planterait sur `correction_required`;
- la rotation de token et les retries doivent etre coordonnes avec l'intercepteur HTTP;
- les ecrans doivent gerer le dossier incomplet, en revue, corrige, rejete et suspendu.

## 11. Recommandations

| Priorite | Probleme | Pourquoi | Solution progressive | Impact | Nature |
|---|---|---|---|---|---|
| P0 | Deux statuts divergents | Decisions incoherentes et autorisations fausses | Designer une source de verite de candidature; synchroniser temporairement l'autre table dans une transaction | Fort backend/BDD | Obligatoire |
| P0 | Roles admin non emis | Endpoints administratifs non exploitables proprement | Charger le compte a l'authentification et deriver les roles cote serveur; integrer Symfony Security | Fort securite/API | Obligatoire |
| P0 | Transitions libres | Approbation/rejet/suspension sans regles ni preuve | Introduire un service de transition explicite avec matrice d'etats, acteur, motif et audit | Fort metier | Obligatoire |
| P0 | Documents non possedes | Usurpation de chemin et fichiers orphelins | Emettre un identifiant d'upload signe rattache a une pre-inscription/utilisateur; verifier sa categorie et sa possession | Fort API/Android | Obligatoire |
| P1 | Parcours d'inscription doubles | Donnees incompletes selon l'endpoint | Declarer un parcours canonique et maintenir l'ancien comme adaptateur de compatibilite | Fort API/Android | Obligatoire |
| P1 | Pas de correction | Workflow cible impossible | Ajouter un etat de correction et une resoumission versionnee sans ecraser le dossier revise | Fort metier/Android | Obligatoire |
| P1 | Admin sans dossier | Verification manuelle impossible | Etendre le detail admin aux documents, vehicule, resultats automatiques et historique | Moyen/fort | Obligatoire |
| P1 | OTP avant transaction | Retry impossible apres erreur SQL | Valider sans consommer puis consommer atomiquement, ou emettre un jeton court de verification OTP | Moyen auth | Obligatoire |
| P1 | Pas d'idempotence | Dossiers multiples sur retry | Ajouter une cle d'idempotence et une contrainte sur le dossier actif | Moyen BDD/API | Obligatoire |
| P1 | Suspension locale | Fonctions sensibles accessibles par oubli | Centraliser une policy `ProviderCapability` utilisee par toutes les operations | Fort securite | Obligatoire |
| P1 | Routes dupliquees | Contrat effectif instable | Choisir API Platform ou routes classiques par domaine, puis deprecier les doublons | Moyen API | Obligatoire |
| P1 | OpenAPI faux | Integration Android fragile | Definir DTO input/output, schemas, erreurs et Bearer auth reels | Moyen API/Android | Obligatoire |
| P2 | Statuts string | Fautes et divergences de casse | Introduire des enums PHP sauvegardes avec valeurs compatibles | Moyen interne | Obligatoire |
| P2 | Pas de verification automatique | Etape cible absente | Ajouter une interface de verification asynchrone et stocker chaque resultat | Fort infra/metier | Obligatoire pour la cible |
| P2 | Refresh token global | Controle de session limite | Stocker des sessions/refresh tokens hashes avec rotation et revocation | Moyen securite/Android | Optionnelle avant lancement |
| P2 | Liste admin non paginee | Charge croissante | Pagination curseur, tri et filtres indexes | Faible/moyen | Optionnelle a court terme |
| P2 | Tests rouges | Absence de filet de securite | Reparer les doubles de classes finales et les attentes de normalisation telephone | Moyen qualite | Obligatoire avant refonte |

## 12. Strategie de migration progressive

### Etape 0 - Stabiliser et mesurer

- figer et commiter l'etat Prestataire actuel;
- reparer la suite de tests;
- documenter les payloads effectivement consommes par Android;
- ajouter des metriques sur les statuts et incoherences;
- inventorier les trois dossiers orphelins avant toute contrainte.

Critere de sortie : build vert et contrats actuels versionnes.

### Etape 1 - Encapsuler l'existant

- introduire des DTO typés et enums compatibles avec les valeurs SQL actuelles;
- creer un service unique de lecture d'un dossier Prestataire agrege;
- centraliser l'identite JWT et les autorisations;
- conserver les endpoints et formats existants comme facades.

Critere de sortie : aucune modification Android obligatoire, comportement couvert par tests.

### Etape 2 - Introduire le workflow sans bascule

- ajouter l'historique de transitions et les champs de decision;
- ajouter l'etat de correction de maniere additive;
- faire passer toutes les commandes admin par le service de transition;
- ecrire temporairement dans la source de verite et synchroniser le statut legacy.

Critere de sortie : anciennes lectures toujours valides, nouvelles decisions auditees.

### Etape 3 - Versionner les dossiers et documents

- rattacher les uploads a un acteur et une candidature;
- creer une nouvelle revision lors d'une correction;
- conserver les documents examines en lecture seule;
- ajouter idempotence et unicite du dossier actif.

Critere de sortie : resoumission sans perte d'historique.

### Etape 4 - Verification automatique

- publier une commande apres soumission;
- executer les controles via un worker asynchrone;
- stocker resultats, score, fournisseur, version et horodatage;
- envoyer le dossier en revue administrative selon une policy explicite.

Critere de sortie : reprise sur erreur et reevaluation possibles sans changer l'API mobile.

### Etape 5 - Migration Android

- ajouter un modele tolerant des nouveaux statuts;
- separer session utilisateur, profil Prestataire et candidature;
- implementer les ecrans de progression/correction/rejet/suspension;
- activer refresh token et retries idempotents;
- basculer vers les contrats OpenAPI stabilises.

Critere de sortie : ancienne et nouvelle version Android supportees pendant la fenetre de
compatibilite.

### Etape 6 - Deprecier l'ancien modele

- comparer les lectures legacy et nouvelles en production;
- corriger les ecarts;
- arreter la double ecriture;
- supprimer les routes et colonnes historiques uniquement apres expiration des versions mobiles
  supportees.

## 13. Decision d'architecture a prendre avant implementation

La premiere decision n'est pas le nom d'une nouvelle table. Il faut definir quelle notion porte
le cycle de vie :

- le profil represente les capacites durables du Prestataire;
- la candidature represente une demande versionnee soumise a verification;
- l'autorisation operationnelle resulte de la derniere decision valide et peut etre suspendue.

Tant que ces trois notions restent confondues dans `account_type`, `provider_profile.status` et
`driver_application.status`, le workflow cible ne peut pas etre rendu fiable.
