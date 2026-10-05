=== Felar Connect pour WooCommerce ===
Contributors: felar
Tags: woocommerce, stock, caisse, gestion, felar
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.9
Stable tag: 1.5.0
License: Proprietary

Votre site reste votre vitrine. Felar devient votre arrière-boutique : stock, caisse, commandes, clients, factures.

== Description ==

Vous avez déjà un site WooCommerce : vos fiches y sont écrites, vos photos y sont
rangées, vos pages sont indexées, vos clients connaissent l'adresse. Vous ne
voulez pas d'un second site — vous voulez que Felar s'occupe de l'arrière.

Cette extension branche les deux. Elle ne remplace rien sur votre site : vos
pages, votre tunnel de commande et vos moyens de paiement restent les vôtres.
Felar ne touche pas à cet argent.

**Qui décide de quoi**

* Votre boutique décide des intitulés, des descriptions, des prix, des photos,
  des catégories et de la TVA. C'est la page que vos clients lisent.
* **Felar décide du stock.** Il bouge à la caisse, à la réception d'une livraison,
  à l'inventaire — des mouvements que votre site ne voit pas. Votre stock de
  départ monte une fois, à l'import ; ensuite c'est Felar qui le tient.
* Vos prix d'achat, vos marges et vos fournisseurs restent dans Felar. Ils n'ont
  rien à faire sur un site public.

**Ce que fait cette version**

1. Vous collez votre clé Felar et vous testez la connexion.
2. Vous **analysez votre catalogue** : combien d'articles, combien de fiches à
   déclinaisons, lesquels n'ont pas d'UGS, lesquels portent la même deux fois, et
   ce qui ne sera pas importé — avec la raison, article par article.
3. Vous lancez l'import. Il avance en tâche de fond et reprend tout seul après une
   coupure.
4. Pour les articles sans UGS, Felar en fabrique une et vous proposez de la
   réécrire dans WooCommerce — pour ne pas avoir deux vocabulaires pour le même
   article, l'un au comptoir, l'autre à l'écran.
5. Vous activez la **synchronisation du stock** : toutes les dix minutes, les
   quantités de Felar descendent sur votre site.
6. Vous activez l'**envoi des commandes** : chaque vente arrive dans Felar dès
   qu'elle est passée, et en ressortent le stock, le chiffre, le client et la
   facture.

7. Quand vous corrigez un prix, un intitulé ou une description **dans Felar**, il
   vous demande s'il faut le porter ici. Vos photos, vos prix d'achat et vos
   marges, eux, ne partent jamais vers votre site.

== Installation ==

1. Envoyez le dossier dans `wp-content/plugins/`, puis activez l'extension.
2. Dans Felar, ouvrez **Réglages → Mon site** et générez une clé. Felar ne
   l'affiche qu'une seule fois : copiez-la tout de suite.
3. Dans WordPress, ouvrez **WooCommerce → Felar Connect**, collez la clé, et
   cliquez sur **Tester la connexion**. Le nom de votre compte Felar doit
   s'afficher.
4. Onglet **Import du catalogue** : analysez, lisez le rapport, importez.

== Frequently Asked Questions ==

= Ma clé commence par ck_test_ : que se passe-t-il ? =

Felar vérifie tout ce que vous envoyez et **n'enregistre rien**. C'est fait pour
éprouver le branchement sans salir votre comptabilité. Passer en production
consiste à coller une clé `ck_live_`, rien d'autre.

= J'ai remboursé un client : que se passe-t-il dans Felar ? =

Si votre remboursement porte sur des **articles** — ce qui est le cas quand vous
les cochez dans WooCommerce — ils remontent dans Felar : la quantité revient en
stock, et un avoir peut être produit sur la facture. Un remboursement partiel
fonctionne aussi bien qu'un total.

Si votre remboursement ne porte qu'un **montant**, sans article, Felar ne le
reprend pas : il ne saurait pas quoi faire de la marchandise, et deviner
fausserait votre inventaire pour des mois. Traitez ce geste commercial dans Felar,
sur l'écran de la commande.

= Mon remboursement est refusé : « commande non livrée » =

Un retour suppose que la marchandise soit partie. Passez d'abord la commande à
« Terminée » dans WooCommerce — c'est ce qui dit à Felar qu'elle a été livrée — puis
enregistrez le remboursement.

= J'ai remboursé un client, Felar ne le voit pas =

Un remboursement décide de deux choses à la fois : ce que devient le stock et ce
que devient la facture. L'enregistrer à moitié vous donnerait une comptabilité
fausse. Faites-le dans Felar, sur l'écran de la commande, où vous voyez ce que
vous rendez.

= Mon site affiche de moins en moins de stock, alors que mes étagères sont pleines =

Vos commandes ne sont probablement jamais passées à **« Terminée »** dans
WooCommerce.

Chaque commande réserve ses articles dans Felar : ils sont encore chez vous, mais
plus vendables. Cette réservation ne se dénoue qu'au moment où vous marquez la
commande terminée — c'est ce qui dit à Felar que la marchandise est partie. Sans
cela, elles s'accumulent et votre disponible descend jusqu'à zéro.

Votre stock physique, lui, n'a pas bougé.

= Mon site affiche moins de stock que Felar =

C'est normal, et c'est voulu. Felar publie le **disponible** : le stock moins ce
que des commandes en cours ont déjà réservé. Vous verrez donc parfois 10 dans
Felar et 8 sur votre site — les deux unités manquantes sont promises à quelqu'un.
Publier le stock physique vous ferait vendre deux fois le même article.

= Un article de Felar ne reçoit aucune quantité =

L'écran *Stock* nomme chaque cas et dit quoi faire. Le plus fréquent : WooCommerce
ne gère pas le stock de cet article. Cochez « Gérer le stock » sur sa fiche. Nous
ne le faisons pas à votre place : cela ferait passer d'un coup des articles en
« rupture », donc invendables sur votre site.

= Felar ne vend plus un article. Et sur mon site ? =

Rien ne change : sa page reste en ligne, et son stock n'est simplement plus publié.
L'écran *Stock* vous le nomme. Dépublier une page que Google indexe est votre
décision, pas la nôtre.

= Mon stock envoyé n'a pas été retenu =

C'est voulu, et l'écran l'annonce. Le stock monte à la **création** d'une fiche ;
ensuite Felar en est maître, parce qu'il voit les ventes au comptoir, les
réceptions et les inventaires que votre site ignore. Un connecteur qui renvoie du
stock à chaque passage travaille pour rien.

= L'import reste à zéro =

Les tâches de fond de WordPress ne partent que s'il y a du trafic sur le site, et
pas du tout si `DISABLE_WP_CRON` est actif. Le bouton **Faire avancer** pousse une
tranche à la main ; pour un site calme, demandez à votre hébergeur un véritable
cron système qui appelle `wp-cron.php`.

= Mes produits variables sont arrivés en produits simples =

Presque toujours parce que le stock est suivi **sur le produit** et non sur chaque
variation : c'est le réglage par défaut de WooCommerce. Les importer en
déclinaisons ferait réclamer à chacune la totalité du stock du parent, et vous
vendriez plusieurs fois le même article. Cochez « Gérer le stock » sur chaque
variation, puis relancez. Le rapport nomme chaque produit concerné.

= Un article n'a pas de catégorie dans ma boutique =

Il part dans la catégorie par défaut de WooCommerce, « Non classé ». Dans Felar,
une fiche est toujours rangée quelque part ; Felar n'invente pas de catégorie à
votre place.

= Et si ma clé fuite ? =

Révoquez-la dans Felar : c'est immédiat et sans délai de grâce. Générez-en une
autre et collez-la ici. Votre catalogue déjà monté n'est pas touché.

== Changelog ==

= 1.5.0 =
* Les remboursements, partiels comme totaux, remontent dans Felar avec leurs
  lignes : le stock revient et un avoir peut être produit.

= 1.4.0 =
* Une commande passée en « Terminée » dans WooCommerce libère la réservation de
  stock dans Felar. Sans cela, votre disponible baissait à chaque vente web sans
  jamais remonter.

= 1.3.0 =
* Les corrections faites dans Felar — prix, intitulé, description — sont posées
  ici après votre accord, jamais sans.
* La valeur réellement affichée est renvoyée à Felar, ce qui empêche la
  propagation de tourner en rond.

= 1.2.0 =
* Les commandes montent dans Felar dès qu'elles sont passées, payées ou non.
* Le règlement et l'annulation suivent ; le numéro de commande Felar est retenu.
* Écran *Commandes* : ce qui est monté, et ce qui demande un regard.

= 1.1.0 =
* Synchronisation du stock, de Felar vers la boutique, toutes les dix minutes.
* Écran *Stock* : ce qui a été écrit, et ce qui ne l'a pas été avec la raison.

= 1.0.0 =
* Appairage et test de la connexion.
* Rapport d'analyse du catalogue, à lire avant d'importer.
* Import du catalogue, déclinaisons comprises, en tâche de fond.
* Retour dans WooCommerce des références fabriquées par Felar.
