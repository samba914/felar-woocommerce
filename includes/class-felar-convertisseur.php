<?php
/**
 * Un produit WooCommerce, traduit en lignes du contrat Felar.
 *
 * <h2>Pourquoi cette classe ne connaît pas WooCommerce</h2>
 * Elle ne reçoit que des tableaux. C'est ce qui permet de l'éprouver sans
 * installer WordPress, et surtout d'écrire un cas de test pour chacune des
 * situations pénibles du commerce réel — stock au niveau du parent, axe à une
 * seule valeur, variation « au choix du client » — au lieu de les découvrir chez
 * le premier marchand. La lecture des objets WooCommerce est le travail de
 * {@see Felar_Lecteur}, et lui seul.
 *
 * <h2>La règle qui décide de tout</h2>
 * Déclinaison ou champ personnalisé ? <b>La donnée de WooCommerce répond
 * déjà</b> : un attribut coché « utilisé pour les variations » est un axe de
 * déclinaison, les autres sont descriptifs. Il n'y a rien à deviner ; c'est le
 * marchand qui a répondu à la question, en remplissant sa boutique.
 *
 * <h2>Le doute se résout toujours vers le produit simple</h2>
 * Chaque fois qu'une déclinaison serait douteuse — stock du parent, valeur
 * indéfinie, axe trop long — le produit entre comme produit <b>simple</b> et le
 * rapport dit pourquoi. Un produit simple de trop est un désagrément ; des
 * déclinaisons qui revendiquent chacune le stock du parent est une survente.
 *
 * Forme attendue d'un produit brut :
 * <pre>
 * array(
 *   'id'            => 1287,
 *   'type'          => 'simple' | 'variable' | 'grouped' | 'external',
 *   'statut'        => 'publish' | 'draft' | 'private' | ...,
 *   'reference'     => 'BZ-SAC-01',
 *   'intitule'      => 'Sac bazin brodé',
 *   'description'   => 'Coton bazin, doublure satin.',
 *   'prix'          => '24500.00',
 *   'classe_taxe'   => '',
 *   'statut_taxe'   => 'taxable',
 *   'suit_le_stock' => true,
 *   'stock'         => 4,
 *   'image'         => 'https://…/sac.jpg',
 *   'categories'    => array( array( 'Maroquinerie', 'Sacs à main' ) ),
 *   'attributs'     => array(
 *       array( 'nom' => 'Taille', 'pour_variations' => true,
 *              'valeurs' => array( '38', '40' ), 'position' => 0 ),
 *   ),
 *   'variations'    => array(
 *       array( 'id' => 1301, 'reference' => 'ROB-38', 'prix' => '32000',
 *              'suit_le_stock' => true, 'stock' => 2, 'image' => '',
 *              'classe_taxe' => '', 'statut_taxe' => 'taxable',
 *              'choix' => array( 'Taille' => '38' ), 'poids' => '0.4' ),
 *   ),
 * )
 * </pre>
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Convertisseur {

	/** @var string Code ISO de la devise du compte Felar. */
	private $devise;

	/** @var bool Réglage de boutique : les prix saisis comprennent-ils la taxe. */
	private $prix_ttc;

	/** @var bool Résultat de `wc_tax_enabled()`. */
	private $taxe_active;

	/** @var array Table `classe de taxe => taux`. */
	private $taux_par_classe;

	/** @var float Le taux déclaré par le marchand. */
	private $taux_declare;

	/** @var string Unité de poids de la boutique, pour nommer le champ. */
	private $unite_poids;

	/** @var bool Importer aussi les brouillons. */
	private $avec_brouillons;

	/**
	 * @var string La catégorie où ranger un article que la boutique n'a pas classé.
	 *
	 * Felar refuse une fiche neuve sans catégorie (`CATEGORY_REQUIRED`) : chez lui,
	 * un article est toujours rangé quelque part. Il faut donc une réponse, et elle
	 * vient de la boutique — « Non classé » de WooCommerce — jamais d'une catégorie
	 * inventée ici.
	 */
	private $categorie_defaut;

	/**
	 * @param string $devise          Devise du compte Felar, la seule acceptée.
	 * @param bool   $prix_ttc        `wc_prices_include_tax()` — réglage global.
	 * @param bool   $taxe_active     `wc_tax_enabled()`.
	 * @param array  $taux_par_classe Voir {@see Felar_Taxes::table_woocommerce()}.
	 * @param float  $taux_declare    Taux de repli déclaré par le marchand.
	 * @param string $unite_poids      'kg', 'g'…
	 * @param bool   $avec_brouillons  Importer les brouillons.
	 * @param string $categorie_defaut Où ranger un article non classé.
	 */
	public function __construct( $devise, $prix_ttc, $taxe_active, array $taux_par_classe, $taux_declare, $unite_poids = 'kg', $avec_brouillons = false, $categorie_defaut = 'Non classé' ) {
		$this->devise          = strtoupper( (string) $devise );
		$this->prix_ttc        = (bool) $prix_ttc;
		$this->taxe_active     = (bool) $taxe_active;
		$this->taux_par_classe = $taux_par_classe;
		$this->taux_declare    = Felar_Taxes::borner( $taux_declare );
		$this->unite_poids     = (string) $unite_poids;
		$this->avec_brouillons = (bool) $avec_brouillons;

		$defaut                 = trim( (string) $categorie_defaut );
		$this->categorie_defaut = '' === $defaut ? 'Non classé' : $defaut;
	}

	/**
	 * Convertit un produit brut.
	 *
	 * @param array $brut Voir la forme documentée en tête de classe.
	 * @return array{mode: string, lignes: array, notes: array, ecarte: ?string}
	 */
	public function convertir( array $brut ) {
		$refus = $this->motif_d_exclusion( $brut );
		if ( null !== $refus ) {
			return $this->ecarte( $refus );
		}

		$verdict = $this->verdict_declinaisons( $brut );
		if ( 'declinaisons' === $verdict['mode'] ) {
			return $this->en_declinaisons( $brut, $verdict );
		}
		return $this->en_produit_simple( $brut, $verdict['notes'] );
	}

	/**
	 * Le motif pour lequel un produit n'entre pas du tout, ou null.
	 *
	 * L'ordre compte : il nomme la cause que le marchand doit traiter en premier.
	 */
	private function motif_d_exclusion( array $brut ) {
		$type = isset( $brut['type'] ) ? $brut['type'] : 'simple';
		if ( in_array( $type, array( 'grouped', 'external', 'variation' ), true ) ) {
			return 'TYPE_NON_GERE';
		}

		$statut = isset( $brut['statut'] ) ? $brut['statut'] : 'publish';
		if ( 'trash' === $statut ) {
			return 'BROUILLON';
		}
		if ( ! in_array( $statut, array( 'publish', 'private' ), true ) && ! $this->avec_brouillons ) {
			return 'BROUILLON';
		}

		if ( '' === trim( (string) ( isset( $brut['intitule'] ) ? $brut['intitule'] : '' ) ) ) {
			return 'SANS_INTITULE';
		}

		if ( 'variable' === $type && empty( $brut['variations'] ) ) {
			return 'SANS_VARIATION';
		}

		if ( ! $this->prix_lisible( isset( $brut['prix'] ) ? $brut['prix'] : '' ) ) {
			return 'SANS_PRIX';
		}

		return null;
	}

	/** Un prix est lisible s'il est numérique — zéro compris, un article offert existe. */
	private function prix_lisible( $prix ) {
		$prix = trim( (string) $prix );
		return '' !== $prix && is_numeric( $prix );
	}

	/**
	 * Déclinaisons ou produit simple, et les notes qui expliquent le choix.
	 *
	 * Toutes les raisons de renoncer sont réunies ici, dans l'ordre où elles
	 * comptent : la survente d'abord, la représentabilité ensuite.
	 *
	 * @return array{mode: string, notes: array, axes: array}
	 */
	private function verdict_declinaisons( array $brut ) {
		$notes = array();

		if ( 'variable' !== ( isset( $brut['type'] ) ? $brut['type'] : 'simple' ) ) {
			return array(
				'mode'  => 'simple',
				'notes' => $notes,
				'axes'  => array(),
			);
		}

		$axes       = $this->axes( $brut );
		$variations = array_values( (array) $brut['variations'] );

		if ( empty( $axes ) ) {
			// Aucun attribut n'est coché « utilisé pour les variations », ou aucun
			// n'offre plus d'une valeur : il n'y a pas d'axe, donc pas de modèle.
			$notes[] = 'AXE_UNIQUE';
			return $this->replier( $notes );
		}

		if ( count( $axes ) > Felar_Contrat::AXES_MAX ) {
			$notes[] = 'TROP_AXES';
			return $this->replier( $notes );
		}

		foreach ( $axes as $axe ) {
			if ( count( $axe['valeurs'] ) > Felar_Contrat::VALEURS_PAR_AXE_MAX ) {
				$notes[] = 'TROP_VALEURS';
				return $this->replier( $notes );
			}
			if ( Felar_Contrat::trop_long( $axe['nom'], Felar_Contrat::OPTION_NOM_MAX ) ) {
				$notes[] = 'VALEUR_TROP_LONGUE';
				return $this->replier( $notes );
			}
		}

		if ( count( $variations ) > Felar_Contrat::DECLINAISONS_PAR_MODELE_MAX ) {
			$notes[] = 'TROP_DECLINAISONS';
			return $this->replier( $notes );
		}

		// Le stock. C'est la raison la plus grave de renoncer : le stock de Felar
		// est porté par la ligne vendable, donc des déclinaisons qui héritent du
		// stock du parent le revendiqueraient chacune en entier.
		$suivis = 0;
		foreach ( $variations as $variation ) {
			if ( ! empty( $variation['suit_le_stock'] ) ) {
				$suivis++;
			}
		}
		$total = count( $variations );

		if ( 0 === $suivis && ! empty( $brut['suit_le_stock'] ) ) {
			$notes[] = 'STOCK_PARENT';
			return $this->replier( $notes );
		}
		if ( $suivis > 0 && $suivis < $total ) {
			$notes[] = 'STOCK_MIXTE';
			return $this->replier( $notes );
		}

		// Chaque variation doit nommer une valeur sur chaque axe. « Au choix du
		// client » — l'attribut laissé vide dans WooCommerce — n'en est pas une.
		foreach ( $variations as $variation ) {
			foreach ( $axes as $axe ) {
				$choix = $this->choix( $variation, $axe['nom'] );
				if ( '' === $choix ) {
					$notes[] = 'AXE_INDEFINI';
					return $this->replier( $notes );
				}
				if ( Felar_Contrat::trop_long( $choix, Felar_Contrat::OPTION_VALEUR_MAX ) ) {
					// La couper serait pire : deux valeurs coupées au même endroit
					// se confondraient en une seule déclinaison, donc en un seul
					// stock pour deux articles.
					$notes[] = 'VALEUR_TROP_LONGUE';
					return $this->replier( $notes );
				}
			}
		}

		return array(
			'mode'  => 'declinaisons',
			'notes' => $notes,
			'axes'  => $axes,
		);
	}

	/** Le repli : produit simple, avec les notes déjà réunies. */
	private function replier( array $notes ) {
		return array(
			'mode'  => 'simple',
			'notes' => $notes,
			'axes'  => array(),
		);
	}

	/**
	 * Les axes de déclinaison : les attributs cochés « utilisé pour les
	 * variations » qui offrent réellement un choix.
	 *
	 * Un axe à une seule valeur n'est pas un axe : un modèle à une déclinaison
	 * est un niveau de navigation en plus pour rien.
	 */
	private function axes( array $brut ) {
		$axes = array();
		foreach ( (array) ( isset( $brut['attributs'] ) ? $brut['attributs'] : array() ) as $attribut ) {
			if ( empty( $attribut['pour_variations'] ) ) {
				continue;
			}
			$valeurs = $this->valeurs( $attribut );
			if ( count( $valeurs ) < 2 ) {
				continue;
			}
			$nom = trim( (string) ( isset( $attribut['nom'] ) ? $attribut['nom'] : '' ) );
			if ( '' === $nom ) {
				continue;
			}
			$axes[] = array(
				'nom'      => $nom,
				'valeurs'  => $valeurs,
				'position' => isset( $attribut['position'] ) ? (int) $attribut['position'] : 0,
			);
		}

		usort(
			$axes,
			function ( $a, $b ) {
				$ecart = $a['position'] - $b['position'];
				return 0 !== $ecart ? $ecart : strcmp( $a['nom'], $b['nom'] );
			}
		);

		return $axes;
	}

	/** Les valeurs d'un attribut, vides retirées. */
	private function valeurs( array $attribut ) {
		$valeurs = array();
		foreach ( (array) ( isset( $attribut['valeurs'] ) ? $attribut['valeurs'] : array() ) as $valeur ) {
			$valeur = trim( (string) $valeur );
			if ( '' !== $valeur ) {
				$valeurs[] = $valeur;
			}
		}
		return $valeurs;
	}

	/** La valeur choisie par une variation sur un axe, insensible à la casse du nom. */
	private function choix( array $variation, $nom_axe ) {
		$choix = isset( $variation['choix'] ) ? (array) $variation['choix'] : array();
		foreach ( $choix as $nom => $valeur ) {
			if ( 0 === strcasecmp( trim( (string) $nom ), trim( (string) $nom_axe ) ) ) {
				return trim( (string) $valeur );
			}
		}
		return '';
	}

	/** Un produit écarté : aucune ligne, un motif nommé. */
	private function ecarte( $code ) {
		return array(
			'mode'   => 'ecarte',
			'lignes' => array(),
			'notes'  => array(),
			'ecarte' => $code,
		);
	}

	/**
	 * Un produit simple : une ligne, tous les attributs en champs personnalisés.
	 *
	 * Vaut aussi pour un produit variable replié. Le prix retenu est alors celui
	 * que WooCommerce affiche — le plus bas de ses variations — et le rapport le
	 * dit, parce que c'est le genre de valeur qu'un marchand doit vérifier.
	 */
	private function en_produit_simple( array $brut, array $notes ) {
		if ( 'variable' === ( isset( $brut['type'] ) ? $brut['type'] : 'simple' ) ) {
			$notes[] = 'PRIX_LE_PLUS_BAS';
		}

		list( $taux, $note_taxe ) = Felar_Taxes::resoudre(
			$this->taxe_active,
			isset( $brut['statut_taxe'] ) ? $brut['statut_taxe'] : 'taxable',
			isset( $brut['classe_taxe'] ) ? $brut['classe_taxe'] : '',
			$this->taux_par_classe,
			$this->taux_declare
		);
		if ( null !== $note_taxe ) {
			$notes[] = $note_taxe;
		}

		$stock = $this->stock_d_un_simple( $brut, $notes );

		$champs = array();
		foreach ( (array) ( isset( $brut['attributs'] ) ? $brut['attributs'] : array() ) as $attribut ) {
			$valeurs = $this->valeurs( $attribut );
			$nom     = trim( (string) ( isset( $attribut['nom'] ) ? $attribut['nom'] : '' ) );
			if ( empty( $valeurs ) || '' === $nom ) {
				continue;
			}
			$champs[] = array(
				'nom'    => $nom,
				'valeur' => implode( ', ', $valeurs ),
			);
		}

		$ligne = $this->ligne(
			array(
				'externalId'  => (string) $brut['id'],
				'reference'   => isset( $brut['reference'] ) ? $brut['reference'] : '',
				'intitule'    => $brut['intitule'],
				'description' => isset( $brut['description'] ) ? $brut['description'] : '',
				'prix'        => $brut['prix'],
				'taux'        => $taux,
				'categories'  => isset( $brut['categories'] ) ? $brut['categories'] : array(),
				'image'       => isset( $brut['image'] ) ? $brut['image'] : '',
				'stock'       => $stock['valeur'],
				'suivi'       => $stock['suivi'],
				'champs'      => $champs,
			),
			$notes
		);

		return array(
			'mode'   => 'simple',
			'lignes' => array( $ligne ),
			'notes'  => array_values( array_unique( $notes ) ),
			'ecarte' => null,
		);
	}

	/**
	 * Le stock d'un produit simple, y compris replié.
	 *
	 * Un produit variable replié dont seules les variations suivent leur stock
	 * n'a pas de stock propre : la somme des variations est alors le seul chiffre
	 * honnête, et il est annoncé comme tel.
	 *
	 * @param array $brut  Le produit brut.
	 * @param array $notes Les notes, enrichies au passage.
	 */
	private function stock_d_un_simple( array $brut, array &$notes ) {
		if ( ! empty( $brut['suit_le_stock'] ) ) {
			return array(
				'valeur' => max( 0, (int) ( isset( $brut['stock'] ) ? $brut['stock'] : 0 ) ),
				'suivi'  => true,
			);
		}

		$somme  = 0;
		$trouve = false;
		foreach ( (array) ( isset( $brut['variations'] ) ? $brut['variations'] : array() ) as $variation ) {
			if ( ! empty( $variation['suit_le_stock'] ) ) {
				$trouve = true;
				$somme += max( 0, (int) ( isset( $variation['stock'] ) ? $variation['stock'] : 0 ) );
			}
		}
		if ( $trouve ) {
			$notes[] = 'STOCK_CUMULE';
			return array(
				'valeur' => $somme,
				'suivi'  => true,
			);
		}

		return array(
			'valeur' => null,
			'suivi'  => false,
		);
	}

	/**
	 * Un produit variable : une ligne par variation, toutes rattachées au même
	 * modèle.
	 *
	 * Deux variations qui portent la même combinaison d'attributs — WooCommerce
	 * le permet — donneraient la même déclinaison dans Felar : la seconde est
	 * laissée de côté ici plutôt que refusée là-bas, pour que le rapport puisse
	 * la nommer.
	 */
	private function en_declinaisons( array $brut, array $verdict ) {
		$notes = $verdict['notes'];
		$axes  = $verdict['axes'];

		$options = array();
		$rang    = 1;
		foreach ( $axes as $axe ) {
			$options[] = array(
				'name'     => Felar_Contrat::couper( $axe['nom'], Felar_Contrat::OPTION_NOM_MAX ),
				'position' => $rang,
			);
			$rang++;
		}

		$modele = array(
			'externalId'  => Felar_Contrat::couper( (string) $brut['id'], Felar_Contrat::EXTID_MAX ),
			'label'       => Felar_Contrat::couper( $brut['intitule'], Felar_Contrat::INTITULE_MAX ),
			'description' => Felar_Contrat::couper( isset( $brut['description'] ) ? $brut['description'] : '', Felar_Contrat::DESCRIPTION_MAX ),
			'options'     => $options,
		);
		if ( '' === $modele['description'] ) {
			unset( $modele['description'] );
		}

		// Les attributs descriptifs sont communs à la fiche : portée MODEL. Ce
		// n'est pas « global » au sens du catalogue entier, c'est « porté par la
		// fiche mère » ; le reste est propre à chaque déclinaison.
		$champs_modele = array();
		foreach ( (array) ( isset( $brut['attributs'] ) ? $brut['attributs'] : array() ) as $attribut ) {
			if ( ! empty( $attribut['pour_variations'] ) ) {
				continue;
			}
			$valeurs = $this->valeurs( $attribut );
			$nom     = trim( (string) ( isset( $attribut['nom'] ) ? $attribut['nom'] : '' ) );
			if ( empty( $valeurs ) || '' === $nom ) {
				continue;
			}
			$champs_modele[] = array(
				'nom'    => $nom,
				'valeur' => implode( ', ', $valeurs ),
				'portee' => Felar_Contrat::PORTEE_MODELE,
			);
		}

		$lignes     = array();
		$signatures = array();

		foreach ( (array) $brut['variations'] as $variation ) {
			$choisis   = array();
			$signature = array();
			foreach ( $axes as $axe ) {
				$valeur      = $this->choix( $variation, $axe['nom'] );
				$choisis[]   = array(
					'name'  => Felar_Contrat::couper( $axe['nom'], Felar_Contrat::OPTION_NOM_MAX ),
					'value' => Felar_Contrat::couper( $valeur, Felar_Contrat::OPTION_VALEUR_MAX ),
				);
				$signature[] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $valeur, 'UTF-8' ) : strtolower( $valeur );
			}

			$empreinte = implode( '|', $signature );
			if ( isset( $signatures[ $empreinte ] ) ) {
				$notes[] = 'DECLINAISON_DOUBLON';
				continue;
			}
			$signatures[ $empreinte ] = true;

			if ( ! $this->prix_lisible( isset( $variation['prix'] ) ? $variation['prix'] : '' ) ) {
				// Une variation sans prix ne se vend pas ; ses sœurs, si.
				$notes[] = 'SANS_PRIX';
				continue;
			}

			list( $taux, $note_taxe ) = Felar_Taxes::resoudre(
				$this->taxe_active,
				isset( $variation['statut_taxe'] ) && '' !== $variation['statut_taxe']
					? $variation['statut_taxe']
					: ( isset( $brut['statut_taxe'] ) ? $brut['statut_taxe'] : 'taxable' ),
				isset( $variation['classe_taxe'] ) && '' !== $variation['classe_taxe']
					? $variation['classe_taxe']
					: ( isset( $brut['classe_taxe'] ) ? $brut['classe_taxe'] : '' ),
				$this->taux_par_classe,
				$this->taux_declare
			);
			if ( null !== $note_taxe ) {
				$notes[] = $note_taxe;
			}

			$champs = $champs_modele;
			$poids  = isset( $variation['poids'] ) ? trim( (string) $variation['poids'] ) : '';
			if ( '' !== $poids && is_numeric( $poids ) && (float) $poids > 0 ) {
				// Le poids est la seule donnée que WooCommerce range réellement par
				// variation : elle mérite la portée DECLINAISON. Le reste des
				// métadonnées de variation n'a pas de place normalisée, et deviner
				// laquelle compte reviendrait à inventer des champs.
				$champs[] = array(
					'nom'    => 'Poids',
					'valeur' => trim( $poids . ' ' . $this->unite_poids ),
					'portee' => Felar_Contrat::PORTEE_DECLINAISON,
				);
			}

			$suffixe = array();
			foreach ( $choisis as $choisi ) {
				$suffixe[] = $choisi['value'];
			}

			$lignes[] = $this->ligne(
				array(
					'externalId'  => (string) $variation['id'],
					'reference'   => isset( $variation['reference'] ) ? $variation['reference'] : '',
					'intitule'    => $brut['intitule'] . ' — ' . implode( ' / ', $suffixe ),
					'description' => isset( $brut['description'] ) ? $brut['description'] : '',
					'prix'        => $variation['prix'],
					'taux'        => $taux,
					'categories'  => isset( $brut['categories'] ) ? $brut['categories'] : array(),
					'image'       => ! empty( $variation['image'] ) ? $variation['image'] : ( isset( $brut['image'] ) ? $brut['image'] : '' ),
					'stock'       => ! empty( $variation['suit_le_stock'] ) ? max( 0, (int) ( isset( $variation['stock'] ) ? $variation['stock'] : 0 ) ) : null,
					'suivi'       => ! empty( $variation['suit_le_stock'] ),
					'champs'      => $champs,
					'modele'      => $modele,
					'choix'       => $choisis,
				),
				$notes
			);
		}

		if ( empty( $lignes ) ) {
			// Toutes les variations sont tombées : il ne reste rien à envoyer, et
			// replier sur un produit simple ferait entrer un article dont on vient
			// justement de constater qu'il n'a pas de prix.
			return $this->ecarte( 'SANS_VARIATION' );
		}

		return array(
			'mode'   => 'declinaisons',
			'lignes' => $lignes,
			'notes'  => array_values( array_unique( $notes ) ),
			'ecarte' => null,
		);
	}

	/**
	 * Assemble une ligne du contrat, bornée, et note ce qui a été coupé.
	 *
	 * Toutes les coupes passent par ici : c'est le seul endroit où une longueur
	 * du contrat est appliquée, donc le seul à relire si le contrat bouge.
	 *
	 * @param array $donnees Les valeurs déjà décidées.
	 * @param array $notes   Les notes, enrichies au passage.
	 */
	private function ligne( array $donnees, array &$notes ) {
		if ( Felar_Contrat::trop_long( $donnees['intitule'], Felar_Contrat::INTITULE_MAX ) ) {
			$notes[] = 'INTITULE_TRONQUE';
		}

		$reference = Felar_Contrat::couper( isset( $donnees['reference'] ) ? $donnees['reference'] : '', Felar_Contrat::REFERENCE_MAX );
		if ( '' === $reference ) {
			$notes[] = 'SANS_REFERENCE';
		}

		$categorie = Felar_Categories::retenir( (array) $donnees['categories'] );
		foreach ( $categorie['notes'] as $note ) {
			$notes[] = $note;
		}

		$image = $this->image( $donnees['image'], $notes );

		$ligne = array(
			'externalId'       => Felar_Contrat::couper( $donnees['externalId'], Felar_Contrat::EXTID_MAX ),
			'label'            => Felar_Contrat::couper( $donnees['intitule'], Felar_Contrat::INTITULE_MAX ),
			'price'            => $this->montant( $donnees['prix'] ),
			'priceIncludesTax' => $this->prix_ttc,
			'taxRate'          => $donnees['taux'],
		);

		if ( '' !== $reference ) {
			$ligne['reference'] = $reference;
		}

		$description = Felar_Contrat::couper( $donnees['description'], Felar_Contrat::DESCRIPTION_MAX );
		if ( '' !== $description ) {
			$ligne['description'] = $description;
		}

		// La catégorie part toujours : Felar refuse une fiche neuve sans elle, et une
		// ligne refusée pour ce motif obligerait le marchand à aller classer l'article
		// dans WooCommerce avant de pouvoir importer quoi que ce soit.
		$ligne['category'] = array(
			'path' => empty( $categorie['chemin'] )
				? array( Felar_Contrat::couper( $this->categorie_defaut, 120 ) )
				: $categorie['chemin'],
		);
		if ( empty( $categorie['chemin'] ) ) {
			$notes[] = 'CATEGORIE_ABSENTE';
		}

		if ( null !== $image ) {
			$ligne['imageUrl'] = $image;
		}

		// `stockTracked` part toujours : c'est une déclaration, et son absence
		// laisserait Felar supposer que le stock est suivi.
		$ligne['stockTracked'] = (bool) $donnees['suivi'];
		if ( null !== $donnees['stock'] ) {
			$ligne['stock'] = (int) $donnees['stock'];
		}

		$champs = $this->champs( isset( $donnees['champs'] ) ? $donnees['champs'] : array(), $notes );
		if ( ! empty( $champs ) ) {
			$ligne['customFields'] = $champs;
		}

		if ( ! empty( $donnees['modele'] ) ) {
			$ligne['model']          = $donnees['modele'];
			$ligne['variantOptions'] = $donnees['choix'];
		}

		return $ligne;
	}

	/** Le montant du contrat ; le raisonnement est dans {@see Felar_Contrat::montant()}. */
	private function montant( $prix ) {
		return Felar_Contrat::montant( $prix, $this->devise );
	}

	/**
	 * L'adresse de l'image, ou null.
	 *
	 * Felar exige du HTTPS publiquement joignable et refuse les adresses privées.
	 * Une image en `http://` sur un site local ne partira donc pas : mieux vaut
	 * l'annoncer ici que de laisser le serveur produire un avertissement par
	 * produit sur les quatre cents lignes du catalogue.
	 *
	 * @param string $adresse L'adresse lue dans WordPress.
	 * @param array  $notes   Les notes, enrichies au passage.
	 */
	private function image( $adresse, array &$notes ) {
		$adresse = trim( (string) $adresse );
		if ( '' === $adresse ) {
			return null;
		}
		if ( 0 !== stripos( $adresse, 'https://' ) || Felar_Contrat::trop_long( $adresse, Felar_Contrat::IMAGE_MAX ) ) {
			$notes[] = 'IMAGE_IGNOREE';
			return null;
		}
		return $adresse;
	}

	/**
	 * Les champs personnalisés, bornés en nombre et en longueur.
	 *
	 * @param array $champs Les champs candidats.
	 * @param array $notes  Les notes, enrichies au passage.
	 */
	private function champs( array $champs, array &$notes ) {
		$sortie = array();
		foreach ( $champs as $champ ) {
			$nom = Felar_Contrat::couper( isset( $champ['nom'] ) ? $champ['nom'] : '', Felar_Contrat::CHAMP_NOM_MAX );
			if ( '' === $nom ) {
				continue;
			}
			if ( count( $sortie ) >= Felar_Contrat::CHAMPS_MAX ) {
				$notes[] = 'CHAMPS_TRONQUES';
				break;
			}
			$sortie[] = array(
				'name'  => $nom,
				'value' => Felar_Contrat::couper( isset( $champ['valeur'] ) ? $champ['valeur'] : '', Felar_Contrat::CHAMP_VALEUR_MAX ),
				'scope' => isset( $champ['portee'] ) ? $champ['portee'] : Felar_Contrat::PORTEE_MODELE,
			);
		}
		return $sortie;
	}
}
