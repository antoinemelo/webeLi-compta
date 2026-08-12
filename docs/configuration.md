# Configuration du dossier

Le référentiel « Devises et change » centralise les devises autorisées, les
taux rationnels datés et sourcés ainsi que les comptes de gains et pertes de
change. La devise de base reste portée par l’identité du dossier.

L’écran Vue `/app/configuration` rassemble les réglages transversaux sans
dupliquer les données métier. Il exige la permission `dossier.manage` sur le
dossier sélectionné.

## Structures

L’onglet **Organisations et dossiers** est utilisable sans dossier sélectionné
par un administrateur d’installation. Il centralise le registre des
organisations, leurs dossiers actifs ou archivés et l’historique daté de leur
identité juridique. Son assistant initialise atomiquement le plan, les modules,
le premier exercice, la première période, le journal général et les références
requises. La raison
sociale, la forme, l’IDE et l’adresse de l’onglet **Entité** sont donc en lecture
seule ; leurs changements passent par ce registre afin d’exiger une date et une
source. Le cycle de vie et les règles de suppression sont détaillés dans
[`organisations-dossiers.md`](organisations-dossiers.md).

La même arborescence contient la gouvernance des accès d’installation,
d’organisation et de dossier. L’ancien enregistrement direct depuis l’onglet
**Accès** est retiré : cet onglet renvoie désormais vers le parcours
prévisualisé, versionné et audité du registre des structures.

## Parcours de configuration initiale

Lorsqu’un gestionnaire sélectionne un dossier réel dont la configuration
initiale n’est pas terminée, un guide compact apparaît en bas à gauche. Les
notifications restent ainsi disponibles en bas à droite. **Précédent** et
**Suivant** suivent l’ordre métier, le bouton principal ouvre directement le
bon panneau. Le compteur et la barre suivent la position réelle dans le
parcours, même si une étape facultative est laissée vide. L’icône « — » réduit
temporairement le guide en un bouton compact. **Annuler** abandonne réellement
le parcours pour le dossier courant sans défaire les réglages déjà enregistrés.
L’entrée **Contexte de travail > Reprendre la configuration initiale**
n’apparaît que pour un parcours ainsi annulé et disparaît après sa reprise ou
sa conclusion.

La progression repose sur les sources métier, pas sur la simple visite d’un
écran :

| Étape | Critère | Nature |
|---|---|---|
| Informations | au moins une identité juridique datée et sourcée | obligatoire |
| Exercices et périodes | chaque exercice est couvert sans trou, puis confirmation explicite | obligatoire |
| Plan et ouverture | écriture d’ouverture validée, ou confirmation explicite d’une ouverture à zéro avant tout mouvement | obligatoire |
| Trésorerie | au moins un compte actif | facultative |
| Compte de facturation | compte de trésorerie actif avec IBAN sélectionné | facultative |
| TVA | régime daté existant puis confirmation explicite | obligatoire |
| Charges sociales | au moins un millésime de taux | facultative |
| Salaires | employeur salarial et mapping comptable enregistrés | facultative |
| Paiements | défaut actif pour les clients et pour les fournisseurs | obligatoire |
| Devises | au moins une devise active | facultative |
| Comptabilité | validation finale lorsque toutes les étapes obligatoires sont terminées | conclusion |

Une étape liée à un module désactivé est non applicable et ne bloque pas la
fin du parcours. Les confirmations sont conservées dans
`parametres_dossier`, les mutations sont auditées et le serveur recontrôle les
prérequis avant toute validation. La suppression ultérieure d’un prérequis
obligatoire fait réapparaître le guide, même après sa conclusion.

À la dernière étape, une seule action **Terminer et ouvrir la comptabilité**
valide la conclusion et ferme le guide. Si un prérequis obligatoire manque, le
bouton renvoie directement vers cette étape au lieu de laisser le parcours
dans un état ambigu.

## Sources de vérité

| Réglage | Source unique |
|---|---|
| Identité légale datée | `attributs_juridiques_organisation`, reflet courant dans `organisations` |
| Coordonnées opérationnelles | `organisations` |
| Devise de base | `dossiers.monnaie` |
| IBAN de facturation | compte actif choisi par `dossiers.compte_tresorerie_facturation_id` |
| Statut TVA daté | `tva_regimes` |
| Modules actifs | `modules_dossier` |
| Conditions et défauts de paiement | `conditions_paiement`, `defauts_conditions_paiement` |
| Comptes bancaires | `comptes_tresorerie` |
| TVA | régimes, taux et codes du module TVA |
| Charges sociales | paramètres annuels du module Salaires |
| Plan, journaux, exercices et périodes | tables du moteur comptable |
| Débiteurs et créanciers | registre `contacts` de Facturation |
| Utilisateurs et droits | tables d’authentification et d’autorisations |
| Traçabilité | `audit_events` |

Le contrat `/api/v1/configuration/references` lit directement ces sources. Il
n’existe plus de seconde projection SQL dans `ConfigurationService`, ni de
chemin vers un formulaire PHP.

Sous `/app/configuration/referentiels`, les référentiels sont gérés dans Vue :

- débiteurs et créanciers : création et édition optimiste de contacts
  multi-rôles via `ContactService`, rattachement facultatif d’une personne à
  une entreprise, suppression si inutilisé ou archivage avec historique ;
- TVA : le bloc **Avec ou sans TVA** crée le régime daté du dossier
  (`non_assujetti`, `assujetti` ou `volontaire`) avec sa date d’effet. Pour un
  dossier assujetti, il recueille le numéro TVA, la méthode, le mode de
  décompte, la périodicité et les comptes TVA. Le même écran gère les taux
  légaux et les codes datés via `VatConfigurationService` ; les codes peuvent
  être importés/exportés en CSV et toutes les références TVA peuvent être
  effacées après contrôle des dépendances ;
- charges sociales : millésimes en ppm via
  `PayrollConfigurationService`, avec import OCAS prévisualisé et contrôlé,
  ainsi qu’import/export CSV des taux annuels ;
- comptes bancaires, postaux, caisse et cartes : création, édition et
  activation via `TreasuryAccountService`, toujours liés au grand livre ;
  plusieurs comptes opérationnels (par exemple BCGe et UBS) peuvent partager
  un même compte général (par exemple 1020 Banque), à condition de conserver
  la même devise et le même sens comptable ;
  suppression si inutilisés ou archivage sinon. L’IBAN des factures est choisi
  uniquement parmi leurs IBAN CH/LI actifs ;
- journaux : création et édition optimiste via `AccountingSetupService` ;
- exercices et périodes : regroupés dans un seul écran ; l’exercice constitue
  l’enveloppe de reporting et ses périodes pilotent les verrouillages de saisie ;
  un exercice ne peut être fermé tant qu’une période reste ouverte ;
- rôles directs du dossier : affectation transactionnelle et auditée, sans
  modifier les rôles hérités de l’organisation ou de l’installation.

Le plan comptable est le premier référentiel de Configuration, sous
`/app/configuration/referentiels/plan`. Les instantanés des factures et fiches
validées restent inchangés.

Les onglets principaux sont ordonnés
**Modules / Entité / Paiements / Référentiels / Salaires / Audit**. Les
formulaires de création volumineux s’ouvrent dans des fenêtres modales :
condition de paiement, compte de trésorerie, taux de change, contact, code TVA,
taux sociaux, exercice et période. L’onglet Audit permet l’effacement explicite
de tout l’audit du dossier. Tous les retours passent par la région de
notifications temporaire.

## Modules

Les modules Apprentissage, Liquidités, Facturation, Comptabilité et Salaires
sont activables par dossier. Une désactivation :

- retire le module de la navigation ;
- refuse aussi ses routes Vue, PHP et API côté serveur avec HTTP 403 ;
- ne supprime ni ne modifie aucune donnée.

La réactivation restaure donc l’accès aux données existantes. Le tableau de
bord et la configuration restent disponibles afin d’éviter de verrouiller le
dossier.

## Sécurité du compte et double authentification

Le menu personnel de l’en-tête ouvre **Sécurité du compte**. Chaque utilisateur
peut y conserver le mot de passe seul ou activer un second facteur :

- une application TOTP standard ; le secret est chiffré au repos avec
  `APP_MFA_KEY` et huit codes de récupération sont affichés une seule fois ;
- un code à six chiffres envoyé à l’adresse du compte, par `mail()` PHP ou par
  SMTP avec TLS et validation du certificat.

La connexion sépare l’adresse e-mail et le mot de passe en deux écrans. Cette
première identification ne consulte pas le registre des utilisateurs et ne
révèle donc pas si le compte existe. Elle est conservée uniquement dans la
session pendant dix minutes et liée à l’adresse IP ainsi qu’à l’agent
utilisateur. Le mot de passe est toujours vérifié avant le second facteur.
Les défis expirent
après dix minutes, sont liés à la session, à l’adresse IP et à l’agent
utilisateur, sont limités à cinq essais et ne peuvent être consommés qu’une
fois. Toute modification du mot de passe ou du mode de sécurité révoque les
autres sessions du compte.

En production, définir une clé aléatoire stable d’au moins 32 caractères et ne
jamais la placer dans le dépôt :

```text
APP_MFA_KEY=<secret aléatoire propre à cette instance>
APP_PUBLIC_URL=https://compta.example/instance
APP_MAIL_TRANSPORT=php
APP_MAIL_FROM=no-reply@compta.example
APP_MAIL_FROM_NAME=Compta
```

Une clé hexadécimale de 64 caractères peut être générée localement avec :

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

La valeur doit rester stable pendant toute la vie de l’instance : la remplacer
rend les secrets TOTP déjà enregistrés indéchiffrables. Elle se place dans
l’environnement (`APP_MFA_KEY`) ou dans `config/local.php` sous
`mfa_encryption_key`, jamais dans Git ni sous le webroot.

Pour un relais SMTP :

```text
APP_MAIL_TRANSPORT=smtp
APP_SMTP_HOST=smtp.example
APP_SMTP_PORT=587
APP_SMTP_ENCRYPTION=tls
APP_SMTP_USERNAME=<utilisateur>
APP_SMTP_PASSWORD=<secret>
```

Le transport `php` délègue à la fonction `mail()` et donc au MTA configuré sur
le serveur. Le transport `smtp` exige un hôte et utilise TLS avec validation du
certificat. `app:doctor` signale l’absence d’URL publique et les capacités
nécessaires ; l’écran Sécurité désactive les méthodes indisponibles.

L’instance de production doit être publiée exclusivement en HTTPS. La session
utilise uniquement un cookie `HttpOnly`, `SameSite=Lax`, un identifiant strict
et renouvelé à chaque frontière de l’authentification.

### Mot de passe oublié

Le lien **Mot de passe oublié ?** ouvre un parcours public qui répond toujours
de la même manière, que l’adresse appartienne ou non à un compte actif. Pour un
compte reconnu, le serveur envoie un lien construit exclusivement depuis
`APP_PUBLIC_URL` ; l’en-tête HTTP `Host` n’est jamais utilisé.

Le sélecteur aléatoire contient 128 bits et le jeton secret 256 bits. Seule
l’empreinte SHA-256 du jeton est conservée. Le lien expire après quinze
minutes, ne peut être consommé qu’une fois et toute nouvelle demande invalide
la précédente. Les demandes sont limitées par adresse et par IP.

Une réinitialisation réussie :

- applique la même politique de douze caractères que la création du compte ;
- incrémente `version_securite` et révoque ainsi toutes les sessions ouvertes ;
- invalide les autres liens et défis e-mail en attente ;
- conserve le second facteur TOTP ou e-mail déjà configuré ;
- est inscrite dans l’audit sans jamais y placer le jeton.

Si l’envoi de messages est indisponible, un opérateur ayant accès au serveur
peut utiliser la commande de secours. Le nouveau mot de passe passe par une
variable d’environnement afin de ne pas apparaître dans l’historique du shell :

```bash
COMPTA_RESET_PASSWORD='une phrase secrète unique' \
  php bin/console user:reset-password --email=utilisateur@example.test
```

Cette commande révoque également les sessions, conserve le MFA et audite
l’opération. Elle ne doit pas être confondue avec la création d’un nouvel
administrateur.

## Conditions de paiement

Une condition possède une direction, un délai entier en jours, une éventuelle
option « fin de mois » et une période de validité. L’échéance est calculée
ainsi : date du document + délai, puis dernier jour du mois obtenu lorsque
l’option est active.

Les défauts client et fournisseur sont datés. Un nouveau défaut ne peut prendre
effet qu’après le dernier déjà enregistré. Lors de la création d’un document,
la condition résolue et ses paramètres sont copiés dans un snapshot. Une
facture émise conserve ainsi son échéance et son historique lorsque les
réglages futurs changent.

Le même écran contient la règle de comptabilisation des paiements du dossier.
Le mode recommandé comptabilise un paiement Liquidités au premier lettrage ;
le mode prudent attend son lettrage complet. Une ligne bancaire associée
déclenche toujours la comptabilisation et un paiement en devise étrangère
attend toujours le lettrage complet. Les paiements détectés dans le journal
sont déjà comptabilisés et ne génèrent jamais une seconde écriture.

Le régime se modifie exclusivement dans
**Configuration > Référentiels > TVA > Avec ou sans TVA**. Le retour à un
régime assujetti exige les informations fiscales et les comptes complets ; il
n’est jamais déduit d’une propriété de l’organisation ou de l’exercice.

## Base canonique et retour arrière

L'identité, les modules et les conditions de paiement font partie du socle
`database/migrations/001_initial.sql`. Le schéma complet de COMPTA 0.6.1 est
toutefois obtenu après application des migrations immuables `001` à `008`.

Une modification structurelle ultérieure reçoit une migration additive à
partir de `009`; elle ne réécrit jamais les huit versions publiées. Sur une base
en service, la mise à niveau passe par
`php bin/console db:migrate --apply --backup`. Un retour arrière s'effectue
toujours par restauration d'une sauvegarde contrôlée, jamais en retirant
manuellement des colonnes ou une version enregistrée. Voir
[`migrations.md`](migrations.md).

Les taux de charges sociales restent ceux du module Salaires, issus de la
correspondance OCAS documentée. Leur import et leur évolution annuelle
demeurent versionnés ; les fiches validées conservent leurs snapshots.

Le périmètre exact pouvant être retiré sans perte fonctionnelle est consigné
dans [`vue-retirement-audit.md`](vue-retirement-audit.md).
