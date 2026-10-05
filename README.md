# Felar Connect pour WooCommerce

Branche une boutique WooCommerce **existante** sur Felar. Le site reste la
vitrine ; Felar devient l'arrière-boutique : stock, caisse, commandes, clients,
factures.

Ce n'est donc pas une extension qui ajoute une fonctionnalité à WooCommerce :
c'est **la moitié visible d'un contrat entre deux logiciels** qui vivent chacun
leur vie. L'autre moitié est l'API Connect de Felar, décrite dans
`backend/sencrm/docs/api-connect-v1.md`. Les deux dépôts ne se compilent pas
ensemble : ce document-là est le seul lien, et il fait foi.

---

## Ce que fait ce lot

| Fait | Pas encore |
|---|---|
| Appairage et test de la connexion (`GET /ping`) | Notification `stock.changed` (lot 6) |
| Rapport d'analyse du catalogue, lu **avant** d'importer | Remboursements (ils se traitent dans Felar) |
| Import du catalogue, déclinaisons comprises (`POST /products/batch`) | |
| Retour des références fabriquées par Felar dans les UGS WooCommerce | |
| Stock de Felar vers la boutique (`GET /products?since=`) | |
| Commandes vers Felar, et leurs suites (`POST /orders`, `/status`) | |
| **Corrections de Felar portées ici**, sur accord (`GET /changes`) | |

---

## Qui possède quel champ

Une donnée à deux maîtres finit par clignoter : chaque synchronisation écrase la
précédente, et personne ne sait plus quelle valeur est la bonne. La frontière est
donc décidée champ par champ, une fois pour toutes.

- **WooCommerce possède** l'intitulé, la description, le prix, les photos, la
  catégorie et la classe de taxe. C'est la page que Google indexe et que le
  client lit.
- **Felar possède le stock.** Il bouge à la caisse, à la réception d'une
  livraison, à l'inventaire — autant de mouvements que la boutique ne voit pas.
  C'est pourquoi `stock` n'est accepté qu'à la **création** d'une fiche, et
  ignoré ensuite avec un avertissement.
- **Felar garde pour lui** le prix d'achat, les marges et les fournisseurs. Ces
  trois-là n'ont rien à faire sur un site public.

---

## L'architecture, et la raison de sa forme

```
Felar_Lecteur        parle à WooCommerce, ne décide rien
      ↓  tableaux nus
Felar_Convertisseur  décide tout, ne connaît pas WooCommerce
      ↓  lignes du contrat
Felar_Client         trois issues : ok / attendre / fatal
      ↓  Felar_Transport (interface)
Felar_Transport_WP   wp_remote_request
```

**La frontière entre les deux premières classes est ce qui rend les règles
éprouvables.** Les situations qui coûtent cher — stock tenu au niveau du produit
parent, variation « au choix du client », axe à une seule valeur, UGS portée par
deux articles — ne se reproduisent pas en installant WordPress. Avec des tableaux
en entrée, chacune devient un cas de test de trois lignes, et on les traite avant
le premier marchand au lieu d'après.

Même raison pour `Felar_Transport` : une clé révoquée, un `429` avec son
`Retry-After`, une coupure au milieu d'un import ne s'obtiennent pas en appelant
un vrai serveur.

### Les fichiers

| Fichier | Rôle |
|---|---|
| `includes/class-felar-contrat.php` | Toutes les bornes du contrat, à un seul endroit |
| `includes/class-felar-convertisseur.php` | Un produit Woo → des lignes Felar. Le cœur |
| `includes/class-felar-rapport.php` | Les compteurs du rapport, et les UGS en double |
| `includes/class-felar-notes.php` | Une phrase par décision, séparée des codes |
| `includes/class-felar-taxes.php` | Classe de taxe → taux, pour le pays de la boutique |
| `includes/class-felar-categories.php` | Plusieurs catégories → la plus profonde |
| `includes/class-felar-client.php` | Les appels, et la classification des issues |
| `includes/class-felar-lecteur.php` | WooCommerce → tableaux nus |
| `includes/class-felar-analyse.php` | L'analyse par tranches |
| `includes/class-felar-import.php` | L'import par Action Scheduler |
| `includes/class-felar-references.php` | Les UGS fabriquées par Felar, réécrites chez Woo |
| `includes/class-felar-stock-regles.php` | Que faire d'une ligne du flux — et quand ne rien faire |
| `includes/class-felar-stock.php` | La lecture périodique du stock, curseur compris |
| `includes/class-felar-commande-convertisseur.php` | Une commande Woo → le contrat. Les calculs d'argent |
| `includes/class-felar-commande-lecteur.php` | Lit une commande par l'API objet, HPOS compris |
| `includes/class-felar-commandes.php` | Les crochets, la file, les suites |
| `includes/class-felar-propagation.php` | Les corrections de Felar, posées ici et accusées |
| `admin/` | L'écran, en trois onglets |

---

## Les pièges WordPress traités d'entrée

- **`managing_stock()` sur une variation ne rend pas un booléen** : elle peut
  rendre la chaîne `'parent'`. Un `if ( $variation->managing_stock() )` est donc
  vrai dans les deux cas — et c'est exactement la confusion qui produit des
  déclinaisons revendiquant chacune la totalité du stock du parent. La
  comparaison est stricte.
- **Un import ne tient pas dans une requête HTTP.** Il passe par Action
  Scheduler, livré avec WooCommerce. Jamais par une boucle qui meurt à la
  trentième seconde en laissant un catalogue à moitié monté.
- **WP-Cron ne tourne que s'il y a du trafic.** Sur un site calme, ou avec
  `DISABLE_WP_CRON`, les tranches ne partent pas. L'écran le dit et propose
  « Faire avancer ».
- **`wp_remote_post` expire à 5 secondes par défaut.** Un envoi dont Felar doit
  télécharger cent images demande plus d'une minute de travail légitime.
- **HPOS** : la compatibilité est déclarée, et elle est vraie — aucune commande
  n'est lue en SQL direct.
- **La clé n'entre jamais dans un journal.** Les fichiers de WooCommerce sont
  lisibles depuis l'administration : un marchand qui envoie son journal au
  support enverrait son mot de passe avec. Tout ce qui ressemble à une clé est
  masqué avant écriture.
- **Capacité et jeton vérifiés sur chaque action.** Les deux, toujours.

---

## Le stock : écrire, jamais retrancher

C'est la règle la plus coûteuse à enfreindre de tout le contrat, et elle ne se voit
pas tout de suite. WooCommerce retire déjà la quantité de son propre stock quand une
commande est passée ; Felar, lui, la réserve et la retire donc d'`available`.
Soustraire la valeur reçue au lieu de la **remplacer** compte chaque vente deux fois,
et le stock s'effondre en quelques jours — sans qu'aucune ligne de journal ne
désigne la cause.

Écrire a un second mérite : la synchronisation devient **idempotente**. La rejouer
ne change rien, ce qui permet de la relancer après une coupure sans se demander où
elle s'était arrêtée — et c'est aussi ce qui rend le bouton « Tout relire » sans
danger.

Deux conséquences dans le code :

- le point de reprise vient du `syncedAt` **de la réponse**, jamais de l'horloge du
  site : une horloge de deux minutes en avance ferait disparaître deux minutes de
  modifications à chaque tour, définitivement ;
- sur plusieurs pages, c'est le `syncedAt` de la **première** page qui est retenu.
  Il a été figé avant la lecture, donc il ne peut pas sauter une modification
  survenue pendant la pagination. On relit quelques lignes au passage suivant, et
  cela ne coûte rien puisque écrire est idempotent.

**Ce passage n'écrit que le stock.** Le flux porte aussi le prix et l'intitulé ; les
reprendre écraserait la page que le marchand a écrite et que Google indexe.

---

## Les commandes : trois pièges d'argent

**Le prix unitaire est net de toute remise.** `get_total()` d'une ligne est le
montant après remises — y compris un code promotionnel portant sur la commande
entière — là où `get_subtotal()` est celui d'avant. C'est le premier qu'il faut :
Felar ne répartit aucune remise globale, puisqu'il faudrait deviner sur quelles
lignes et à quel taux, et le total du marchand ne tomberait plus juste.

**Tout part hors taxe, quoi qu'en dise le catalogue.** WooCommerce range les
totaux de ligne d'une commande toujours hors taxe, même quand le marchand saisit
ses prix TTC : ce réglage parle de la saisie du catalogue, pas de la commande. On
envoie du HT et on le déclare.

**Le taux se déduit de la commande, pas du produit.** Celui du catalogue a pu
changer depuis ; celui qui compte est celui qui a été facturé, c'est-à-dire le
rapport entre la taxe de la ligne et son montant.

Et une quatrième précaution, côté serveur cette fois : Felar recompose la somme des
lignes et refuse au-delà de **5 % d'écart** avec le total annoncé. Ce n'est pas de
la méfiance — c'est le seul garde-fou contre une boutique tenue en **centimes** là
où le compte est en francs, une panne où rien n'est « invalide » et où le chiffre
d'affaires serait multiplié par cent.

### Pourquoi rien n'est envoyé depuis la requête du client

Un appel réseau pendant la validation du panier allonge l'attente de l'acheteur, et
une panne de Felar ferait échouer sa commande. L'envoi passe par une tâche de fond.

---

## La propagation, et la boucle qu'elle évite

Felar ne pousse jamais de lui-même : il met de côté, demande au marchand, et
seules les corrections **acceptées** descendent jusqu'ici. Trois champs
seulement — prix, intitulé, description — jamais les photos ni les marges.

L'accusé de réception porte **la valeur réellement posée**, pas celle demandée.
C'est le point qui compte, et ce n'est pas une politesse : WooCommerce arrondit à
deux décimales, convertit en hors taxe si le marchand saisit ainsi, et tronque un
intitulé trop long. Si Felar retenait sa propre valeur, sa relecture suivante du
catalogue y verrait un écart, croirait à une modification du site, et reproposerait
la même propagation. Indéfiniment.

On relit donc le prix tel que la boutique l'affiche après écriture
(`wc_get_price_including_tax`), et c'est lui qu'on renvoie.

Une modification qu'on n'a pas pu poser part avec son `error` : elle **reste**
dans la file de Felar, et le marchand la voit encore. Une page supprimée ne doit
pas effacer sa décision.

### Pourquoi deux crochets pour le règlement

`woocommerce_payment_complete` ne part pas quand le marchand marque une commande
payée à la main ; le changement d'état ne part pas toujours sur les passerelles qui
encaissent sans transition. Les deux ensemble couvrent les deux chemins — et le
second envoi est sans conséquence, puisque Felar n'encaisse pas deux fois.

---

## Éprouver

Les tests ne demandent ni WordPress ni réseau :

```bash
composer install
composer test
```

Ils couvrent le convertisseur (dont chaque situation qui interdit les
déclinaisons), le rapport, les bornes du contrat, la TVA, les catégories et les
quatre issues du client.

### Un vrai site, pour ce que les tests ne peuvent pas voir

Un WordPress sur SQLite suffit, servi par le serveur intégré de PHP. Ce que cela
vérifie et que rien d'autre ne vérifie : la lecture des objets WooCommerce —
attributs, variations, arborescence de catégories, classes de taxe.

C'est ainsi qu'ont été trouvés les deux défauts les plus coûteux de ce lot : un
brouillon qui n'atteignait jamais le rapport, et une catégorie obligatoire côté
Felar que le contrat annonçait facultative.
