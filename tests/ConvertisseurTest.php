<?php
/**
 * Les règles de traduction d'un produit WooCommerce.
 *
 * <h2>Ce que ces cas protègent</h2>
 * La décision « déclinaison ou champ personnalisé », et surtout les quatre
 * situations où des déclinaisons produiraient une <b>survente</b>. Aucune ne se
 * découvre en lisant le code de WooCommerce : elles se découvrent chez un marchand,
 * après coup, quand le dernier exemplaire a été vendu cinq fois.
 *
 * @package Felar_Connect
 */

use PHPUnit\Framework\TestCase;

class ConvertisseurTest extends TestCase {

	/** Un convertisseur de boutique sénégalaise : XOF, prix TTC, TVA à 18 %. */
	private function convertisseur( array $surcharges = array() ) {
		$reglages = array_merge(
			array(
				'devise'      => 'XOF',
				'prix_ttc'    => true,
				'taxe_active' => true,
				'taux'        => array( '' => 18.0 ),
				'declare'     => 0.0,
				'poids'       => 'kg',
				'brouillons'  => false,
				'categorie'   => 'Non classé',
			),
			$surcharges
		);

		return new Felar_Convertisseur(
			$reglages['devise'],
			$reglages['prix_ttc'],
			$reglages['taxe_active'],
			$reglages['taux'],
			$reglages['declare'],
			$reglages['poids'],
			$reglages['brouillons'],
			$reglages['categorie']
		);
	}

	public function test_un_produit_simple_part_entier() {
		$resultat = $this->convertisseur()->convertir( Produits::simple() );

		$this->assertSame( 'simple', $resultat['mode'] );
		$this->assertCount( 1, $resultat['lignes'] );

		$ligne = $resultat['lignes'][0];
		$this->assertSame( '1287', $ligne['externalId'] );
		$this->assertSame( 'BZ-SAC-01', $ligne['reference'] );
		$this->assertSame( 'Sac bazin brodé', $ligne['label'] );
		$this->assertSame( array( 'value' => '24500', 'currency' => 'XOF' ), $ligne['price'] );
		$this->assertTrue( $ligne['priceIncludesTax'] );
		$this->assertSame( 18.0, $ligne['taxRate'] );
		$this->assertSame( 4, $ligne['stock'] );
		$this->assertTrue( $ligne['stockTracked'] );
		$this->assertSame( array( 'Maroquinerie', 'Sacs à main' ), $ligne['category']['path'] );
		$this->assertSame( 'https://chez-fatou.sn/sac-bazin.jpg', $ligne['imageUrl'] );
		$this->assertArrayNotHasKey( 'model', $ligne );
	}

	public function test_le_sens_de_la_tva_vient_de_la_boutique() {
		// Se tromper ici inverse la TVA sur tout un catalogue, sans que rien ne le
		// signale avant la première facture.
		$resultat = $this->convertisseur( array( 'prix_ttc' => false ) )
			->convertir( Produits::simple() );

		$this->assertFalse( $resultat['lignes'][0]['priceIncludesTax'] );
	}

	public function test_taxe_desactivee_emploie_le_taux_declare() {
		$resultat = $this->convertisseur(
			array(
				'taxe_active' => false,
				'declare'     => 0.0,
			)
		)->convertir( Produits::simple() );

		$this->assertSame( 0.0, $resultat['lignes'][0]['taxRate'] );
		$this->assertNotContains( 'TAUX_INTROUVABLE', $resultat['notes'] );
	}

	public function test_une_classe_de_taxe_sans_taux_est_signalee() {
		$resultat = $this->convertisseur( array( 'declare' => 18.0 ) )
			->convertir( Produits::simple( array( 'classe_taxe' => 'reduced-rate' ) ) );

		$this->assertSame( 18.0, $resultat['lignes'][0]['taxRate'] );
		$this->assertContains( 'TAUX_INTROUVABLE', $resultat['notes'] );
	}

	public function test_un_produit_non_taxable_part_a_zero() {
		$resultat = $this->convertisseur()
			->convertir( Produits::simple( array( 'statut_taxe' => 'none' ) ) );

		$this->assertSame( 0.0, $resultat['lignes'][0]['taxRate'] );
	}

	public function test_un_produit_variable_devient_des_declinaisons() {
		$resultat = $this->convertisseur()->convertir( Produits::variable() );

		$this->assertSame( 'declinaisons', $resultat['mode'] );
		$this->assertCount( 2, $resultat['lignes'] );

		$premiere = $resultat['lignes'][0];
		$seconde  = $resultat['lignes'][1];

		// Le même modèle, et les mêmes axes : une déclinaison qui déclarerait
		// d'autres axes que sa sœur serait refusée par Felar.
		$this->assertSame( '1301', $premiere['model']['externalId'] );
		$this->assertSame( '1301', $seconde['model']['externalId'] );
		$this->assertSame( $premiere['model']['options'], $seconde['model']['options'] );
		$this->assertSame( array( array( 'name' => 'Taille', 'position' => 1 ) ), $premiere['model']['options'] );

		$this->assertSame( array( array( 'name' => 'Taille', 'value' => '38' ) ), $premiere['variantOptions'] );
		$this->assertSame( 'Robe écrue — 38', $premiere['label'] );
		$this->assertSame( 2, $premiere['stock'] );

		// L'attribut descriptif est commun à la fiche mère, pas un axe.
		$this->assertSame(
			array( array( 'name' => 'Matière', 'value' => 'Coton', 'scope' => 'MODEL' ) ),
			$premiere['customFields']
		);
	}

	public function test_le_poids_dune_variation_est_un_champ_de_declinaison() {
		$produit = Produits::variable(
			array(
				'variations' => array(
					Produits::variation( 130138, '38', array( 'poids' => '0.4' ) ),
					Produits::variation( 130140, '40', array( 'poids' => '0.45' ) ),
				),
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );
		$champs   = $resultat['lignes'][0]['customFields'];

		$this->assertSame( 'Poids', $champs[1]['name'] );
		$this->assertSame( '0.4 kg', $champs[1]['value'] );
		$this->assertSame( 'VARIANT', $champs[1]['scope'] );
	}

	public function test_le_stock_au_niveau_du_parent_interdit_les_declinaisons() {
		// Le cas par défaut de WooCommerce, et le plus dangereux : cinq tailles
		// revendiqueraient chacune le stock du parent.
		$produit = Produits::variable(
			array(
				'suit_le_stock' => true,
				'stock'         => 7,
				'variations'    => array(
					Produits::variation( 130138, '38', array( 'suit_le_stock' => false, 'stock' => null ) ),
					Produits::variation( 130140, '40', array( 'suit_le_stock' => false, 'stock' => null ) ),
				),
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );

		$this->assertSame( 'simple', $resultat['mode'] );
		$this->assertContains( 'STOCK_PARENT', $resultat['notes'] );
		$this->assertCount( 1, $resultat['lignes'] );
		$this->assertSame( 7, $resultat['lignes'][0]['stock'] );

		// Les attributs, axes compris, deviennent des champs personnalisés : sans
		// quoi l'information « Taille : 38, 40 » serait perdue.
		$noms = array_column( $resultat['lignes'][0]['customFields'], 'name' );
		$this->assertSame( array( 'Taille', 'Matière' ), $noms );
		$this->assertSame( '38, 40', $resultat['lignes'][0]['customFields'][0]['value'] );
	}

	public function test_un_stock_mixte_interdit_les_declinaisons() {
		$produit = Produits::variable(
			array(
				'variations' => array(
					Produits::variation( 130138, '38' ),
					Produits::variation( 130140, '40', array( 'suit_le_stock' => false, 'stock' => null ) ),
				),
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );

		$this->assertSame( 'simple', $resultat['mode'] );
		$this->assertContains( 'STOCK_MIXTE', $resultat['notes'] );
		// Le parent ne suit rien : la somme des variations suivies est le seul
		// chiffre honnête, et il est annoncé.
		$this->assertContains( 'STOCK_CUMULE', $resultat['notes'] );
		$this->assertSame( 2, $resultat['lignes'][0]['stock'] );
	}

	public function test_un_axe_a_une_seule_valeur_ne_merite_pas_un_modele() {
		$produit = Produits::variable(
			array(
				'attributs'  => array(
					array(
						'nom'             => 'Taille',
						'pour_variations' => true,
						'valeurs'         => array( 'Unique' ),
						'position'        => 0,
					),
				),
				'variations' => array( Produits::variation( 130138, 'Unique' ) ),
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );

		$this->assertSame( 'simple', $resultat['mode'] );
		$this->assertContains( 'AXE_UNIQUE', $resultat['notes'] );
	}

	public function test_une_variation_au_choix_du_client_interdit_les_declinaisons() {
		$produit = Produits::variable(
			array(
				'variations' => array(
					Produits::variation( 130138, '38' ),
					Produits::variation( 130140, '' ),
				),
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );

		$this->assertSame( 'simple', $resultat['mode'] );
		$this->assertContains( 'AXE_INDEFINI', $resultat['notes'] );
	}

	public function test_plus_de_trois_axes_replie_sur_un_produit_simple() {
		$attributs  = array();
		$choix      = array();
		for ( $rang = 1; $rang <= 4; $rang++ ) {
			$attributs[] = array(
				'nom'             => 'Axe ' . $rang,
				'pour_variations' => true,
				'valeurs'         => array( 'A', 'B' ),
				'position'        => $rang,
			);
			$choix[ 'Axe ' . $rang ] = 'A';
		}

		$produit = Produits::variable(
			array(
				'attributs'  => $attributs,
				'variations' => array( Produits::variation( 130138, 'A', array( 'choix' => $choix ) ) ),
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );

		$this->assertSame( 'simple', $resultat['mode'] );
		$this->assertContains( 'TROP_AXES', $resultat['notes'] );
	}

	public function test_plus_de_cinquante_valeurs_replie_sur_un_produit_simple() {
		$valeurs = array();
		for ( $rang = 1; $rang <= 51; $rang++ ) {
			$valeurs[] = 'V' . $rang;
		}

		$produit = Produits::variable(
			array(
				'attributs'  => array(
					array(
						'nom'             => 'Taille',
						'pour_variations' => true,
						'valeurs'         => $valeurs,
						'position'        => 0,
					),
				),
				'variations' => array( Produits::variation( 130138, 'V1' ) ),
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );

		$this->assertSame( 'simple', $resultat['mode'] );
		$this->assertContains( 'TROP_VALEURS', $resultat['notes'] );
	}

	public function test_plus_de_deux_cents_declinaisons_replie_sur_un_produit_simple() {
		// La borne est celle du catalogue de Felar : la franchir ferait traverser la
		// validation pour échouer plus bas, sur un message qui n'expliquerait rien.
		$variations = array();
		for ( $rang = 1; $rang <= 201; $rang++ ) {
			$variations[] = Produits::variation( 200000 + $rang, 'T' . $rang );
		}
		$valeurs = array();
		for ( $rang = 1; $rang <= 201; $rang++ ) {
			$valeurs[] = 'T' . $rang;
		}

		$produit = Produits::variable(
			array(
				'attributs'  => array(
					array(
						'nom'             => 'Taille',
						'pour_variations' => true,
						'valeurs'         => array_slice( $valeurs, 0, 50 ),
						'position'        => 0,
					),
				),
				'variations' => $variations,
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );

		$this->assertSame( 'simple', $resultat['mode'] );
		$this->assertContains( 'TROP_DECLINAISONS', $resultat['notes'] );
	}

	public function test_une_valeur_trop_longue_nest_jamais_coupee() {
		// La couper confondrait deux déclinaisons en une : un seul stock pour deux
		// articles, ce qui est précisément la survente qu'on cherche à éviter.
		$longue = str_repeat( 'a', 70 );
		$autre  = str_repeat( 'a', 65 ) . 'zzzzz';

		$produit = Produits::variable(
			array(
				'attributs'  => array(
					array(
						'nom'             => 'Coloris',
						'pour_variations' => true,
						'valeurs'         => array( $longue, $autre ),
						'position'        => 0,
					),
				),
				'variations' => array(
					Produits::variation( 130138, $longue, array( 'choix' => array( 'Coloris' => $longue ) ) ),
					Produits::variation( 130140, $autre, array( 'choix' => array( 'Coloris' => $autre ) ) ),
				),
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );

		$this->assertSame( 'simple', $resultat['mode'] );
		$this->assertContains( 'VALEUR_TROP_LONGUE', $resultat['notes'] );
	}

	public function test_deux_variations_identiques_ne_font_quune_ligne() {
		$produit = Produits::variable(
			array(
				'variations' => array(
					Produits::variation( 130138, '38' ),
					Produits::variation( 130139, '38' ),
					Produits::variation( 130140, '40' ),
				),
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );

		$this->assertCount( 2, $resultat['lignes'] );
		$this->assertContains( 'DECLINAISON_DOUBLON', $resultat['notes'] );
	}

	public function test_un_variable_sans_aucun_stock_suivi_garde_ses_declinaisons() {
		$produit = Produits::variable(
			array(
				'suit_le_stock' => false,
				'variations'    => array(
					Produits::variation( 130138, '38', array( 'suit_le_stock' => false, 'stock' => null ) ),
					Produits::variation( 130140, '40', array( 'suit_le_stock' => false, 'stock' => null ) ),
				),
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );

		// Personne ne suit le stock : il n'y a rien à revendiquer, donc rien à
		// craindre. Les déclinaisons restent.
		$this->assertSame( 'declinaisons', $resultat['mode'] );
		$this->assertFalse( $resultat['lignes'][0]['stockTracked'] );
		$this->assertArrayNotHasKey( 'stock', $resultat['lignes'][0] );
	}

	public function test_un_produit_groupe_est_ecarte() {
		$resultat = $this->convertisseur()->convertir( Produits::simple( array( 'type' => 'grouped' ) ) );

		$this->assertSame( 'ecarte', $resultat['mode'] );
		$this->assertSame( 'TYPE_NON_GERE', $resultat['ecarte'] );
		$this->assertSame( array(), $resultat['lignes'] );
	}

	public function test_un_brouillon_reste_chez_lui_sauf_demande_contraire() {
		$brouillon = Produits::simple( array( 'statut' => 'draft' ) );

		$this->assertSame( 'BROUILLON', $this->convertisseur()->convertir( $brouillon )['ecarte'] );
		$this->assertSame(
			'simple',
			$this->convertisseur( array( 'brouillons' => true ) )->convertir( $brouillon )['mode']
		);
	}

	public function test_la_corbeille_reste_dehors_meme_si_on_demande_les_brouillons() {
		$resultat = $this->convertisseur( array( 'brouillons' => true ) )
			->convertir( Produits::simple( array( 'statut' => 'trash' ) ) );

		$this->assertSame( 'BROUILLON', $resultat['ecarte'] );
	}

	public function test_un_produit_sans_prix_est_ecarte_mais_un_produit_offert_passe() {
		$this->assertSame(
			'SANS_PRIX',
			$this->convertisseur()->convertir( Produits::simple( array( 'prix' => '' ) ) )['ecarte']
		);

		$offert = $this->convertisseur()->convertir( Produits::simple( array( 'prix' => '0' ) ) );
		$this->assertSame( 'simple', $offert['mode'] );
		$this->assertSame( '0', $offert['lignes'][0]['price']['value'] );
	}

	public function test_une_image_en_clair_est_ecartee_et_signalee() {
		$resultat = $this->convertisseur()
			->convertir( Produits::simple( array( 'image' => 'http://chez-fatou.sn/sac.jpg' ) ) );

		$this->assertArrayNotHasKey( 'imageUrl', $resultat['lignes'][0] );
		$this->assertContains( 'IMAGE_IGNOREE', $resultat['notes'] );
	}

	public function test_la_categorie_la_plus_profonde_gagne() {
		$resultat = $this->convertisseur()->convertir(
			Produits::simple(
				array(
					'categories' => array(
						array( 'Promotions' ),
						array( 'Maroquinerie', 'Sacs à main', 'Bandoulière' ),
					),
				)
			)
		);

		$this->assertSame(
			array( 'Maroquinerie', 'Sacs à main', 'Bandoulière' ),
			$resultat['lignes'][0]['category']['path']
		);
		$this->assertContains( 'CATEGORIE_MULTIPLE', $resultat['notes'] );
	}

	public function test_un_article_non_classe_part_quand_meme_avec_la_categorie_de_la_boutique() {
		// Felar refuse une fiche neuve sans catégorie — `product.category_id` est
		// NOT NULL. Laisser partir la ligne sans elle ferait refuser tous les articles
		// que le marchand n'a pas classés, c'est-à-dire la moitié des catalogues.
		$resultat = $this->convertisseur()
			->convertir( Produits::simple( array( 'categories' => array() ) ) );

		$this->assertSame( array( 'Non classé' ), $resultat['lignes'][0]['category']['path'] );
		$this->assertContains( 'CATEGORIE_ABSENTE', $resultat['notes'] );
	}

	public function test_un_intitule_trop_long_est_coupe_et_signale() {
		$resultat = $this->convertisseur()
			->convertir( Produits::simple( array( 'intitule' => str_repeat( 'é', 300 ) ) ) );

		$this->assertSame( 255, mb_strlen( $resultat['lignes'][0]['label'], 'UTF-8' ) );
		$this->assertContains( 'INTITULE_TRONQUE', $resultat['notes'] );
	}

	public function test_un_produit_sans_ugs_part_sans_reference_et_le_dit() {
		$resultat = $this->convertisseur()->convertir( Produits::simple( array( 'reference' => '' ) ) );

		$this->assertArrayNotHasKey( 'reference', $resultat['lignes'][0] );
		$this->assertContains( 'SANS_REFERENCE', $resultat['notes'] );
	}

	public function test_une_variation_sans_prix_ne_fait_pas_tomber_ses_soeurs() {
		$produit = Produits::variable(
			array(
				'variations' => array(
					Produits::variation( 130138, '38' ),
					Produits::variation( 130140, '40', array( 'prix' => '' ) ),
				),
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );

		$this->assertCount( 1, $resultat['lignes'] );
		$this->assertSame( '130138', $resultat['lignes'][0]['externalId'] );
		$this->assertContains( 'SANS_PRIX', $resultat['notes'] );
	}

	public function test_un_variable_dont_aucune_variation_na_de_prix_est_ecarte() {
		$produit = Produits::variable(
			array(
				'variations' => array(
					Produits::variation( 130138, '38', array( 'prix' => '' ) ),
					Produits::variation( 130140, '40', array( 'prix' => '' ) ),
				),
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );

		$this->assertSame( 'ecarte', $resultat['mode'] );
		$this->assertSame( 'SANS_VARIATION', $resultat['ecarte'] );
	}

	public function test_un_prix_decimal_ne_traine_aucun_artefact_de_flottant() {
		$resultat = $this->convertisseur()->convertir( Produits::simple( array( 'prix' => '1234.56' ) ) );

		$this->assertSame( '1234.56', $resultat['lignes'][0]['price']['value'] );
	}

	public function test_un_variable_replie_annonce_que_le_prix_est_le_plus_bas() {
		$produit = Produits::variable(
			array(
				'suit_le_stock' => true,
				'stock'         => 3,
				'prix'          => '29000',
				'variations'    => array(
					Produits::variation( 130138, '38', array( 'suit_le_stock' => false ) ),
					Produits::variation( 130140, '40', array( 'suit_le_stock' => false ) ),
				),
			)
		);

		$resultat = $this->convertisseur()->convertir( $produit );

		$this->assertContains( 'PRIX_LE_PLUS_BAS', $resultat['notes'] );
		$this->assertSame( '29000', $resultat['lignes'][0]['price']['value'] );
	}
}
