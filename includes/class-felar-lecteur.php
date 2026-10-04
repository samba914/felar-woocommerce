<?php
/**
 * Lit WooCommerce, et rien d'autre.
 *
 * Aucune décision n'est prise ici : cette classe traduit des objets WooCommerce
 * en tableaux nus, et {@see Felar_Convertisseur} décide. La frontière tient parce
 * que c'est elle qui rend les règles éprouvables sans WordPress.
 *
 * <h2>Le piège de `managing_stock()` sur une variation</h2>
 * Pour une variation, WooCommerce ne rend pas un booléen mais parfois la chaîne
 * <code>'parent'</code> — « mon stock est celui du produit parent ». Un
 * <code>if ( $variation->managing_stock() )</code> est donc vrai dans les deux
 * cas, et c'est exactement la confusion qui produit des déclinaisons revendiquant
 * chacune la totalité du stock du parent. La comparaison est <b>stricte</b>, et
 * c'est le cœur de la règle du stock.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Lecteur {

	/** @var array Mémoire des chemins de catégorie, par identifiant de terme. */
	private $chemins = array();

	/**
	 * Les identifiants des produits à parcourir, dans un ordre stable.
	 *
	 * L'ordre importe : l'analyse et l'import avancent par tranches, et un ordre
	 * qui change entre deux tranches ferait sauter des produits et en compter
	 * d'autres deux fois.
	 *
	 * <h3>Les brouillons sont lus, puis écartés plus loin</h3>
	 * Les filtrer ici serait plus rapide et ce serait une erreur : le marchand ne
	 * saurait jamais que douze articles sont restés dehors. Le rapport promet qu'aucun
	 * produit n'est écarté en silence, et cette promesse se tient en laissant
	 * {@see Felar_Convertisseur} décider — c'est lui qui nomme le motif. Seule la
	 * corbeille n'est pas lue : personne n'attend d'y retrouver un article.
	 *
	 * @return int[]
	 */
	public function identifiants() {
		$identifiants = wc_get_products(
			array(
				'status'  => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'type'    => array( 'simple', 'variable', 'grouped', 'external' ),
				'limit'   => -1,
				'return'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		return array_map( 'intval', (array) $identifiants );
	}

	/**
	 * Un produit, en tableau nu.
	 *
	 * @param int $id L'identifiant WooCommerce.
	 * @return array|null Null si le produit a disparu entre l'analyse et l'envoi.
	 */
	public function lire( $id ) {
		$produit = wc_get_product( (int) $id );
		if ( ! $produit ) {
			return null;
		}

		$brut = array(
			'id'            => $produit->get_id(),
			'type'          => $produit->get_type(),
			'statut'        => $produit->get_status(),
			'reference'     => (string) $produit->get_sku(),
			'intitule'      => $produit->get_name(),
			'description'   => $this->description( $produit ),
			'prix'          => (string) $produit->get_price(),
			'classe_taxe'   => (string) $produit->get_tax_class(),
			'statut_taxe'   => (string) $produit->get_tax_status(),
			'suit_le_stock' => true === $produit->managing_stock(),
			'stock'         => $produit->get_stock_quantity(),
			'image'         => $this->image( $produit->get_image_id() ),
			'categories'    => $this->categories( $produit ),
			'attributs'     => $this->attributs( $produit ),
			'variations'    => array(),
		);

		if ( $produit->is_type( 'variable' ) ) {
			$brut['variations'] = $this->variations( $produit );
		}

		return $brut;
	}

	/**
	 * La description, débarrassée de ce qui ne veut rien dire ailleurs.
	 *
	 * Les codes courts (`[woocommerce_...]`) et le HTML de mise en page n'ont pas
	 * de sens dans une fiche Felar, ni sur une facture. La description courte sert
	 * de repli : beaucoup de boutiques ne remplissent qu'elle.
	 */
	private function description( $produit ) {
		$texte = (string) $produit->get_description();
		if ( '' === trim( $texte ) ) {
			$texte = (string) $produit->get_short_description();
		}
		$texte = wp_strip_all_tags( strip_shortcodes( $texte ) );
		$texte = html_entity_decode( $texte, ENT_QUOTES, 'UTF-8' );
		$texte = preg_replace( '/[ \t]+/', ' ', $texte );
		$texte = preg_replace( '/\n{3,}/', "\n\n", $texte );
		return trim( (string) $texte );
	}

	/** L'adresse publique d'une image, ou la chaîne vide. */
	private function image( $piece_jointe ) {
		if ( ! $piece_jointe ) {
			return '';
		}
		$adresse = wp_get_attachment_image_url( (int) $piece_jointe, 'full' );
		return is_string( $adresse ) ? $adresse : '';
	}

	/**
	 * Les chemins de catégorie du produit, de la racine vers la feuille.
	 *
	 * <h3>« Non classé » est gardé, et ce n'est pas un oubli</h3>
	 * On pourrait trouver cette catégorie vide de sens et l'écarter. Ce serait une
	 * erreur : dans Felar, {@code product.category_id} est NOT NULL — une fiche est
	 * toujours rangée quelque part — et une ligne sans catégorie est <b>refusée</b>
	 * (`CATEGORY_REQUIRED`). « Non classé » est la réponse que la boutique donne
	 * elle-même à « où est cet article ? », et c'est la seule que nous ayons le
	 * droit de transmettre : en inventer une ajouterait au catalogue du marchand un
	 * rangement qu'il n'a pas voulu.
	 */
	private function categories( $produit ) {
		$chemins = array();
		foreach ( (array) $produit->get_category_ids() as $terme ) {
			$chemin = $this->chemin_de_categorie( (int) $terme );
			if ( ! empty( $chemin ) ) {
				$chemins[] = $chemin;
			}
		}
		return $chemins;
	}

	/** Le chemin d'un terme, mis en mémoire : un catalogue repasse mille fois par les mêmes. */
	private function chemin_de_categorie( $terme ) {
		if ( isset( $this->chemins[ $terme ] ) ) {
			return $this->chemins[ $terme ];
		}

		$objet = get_term( $terme, 'product_cat' );
		if ( ! $objet || is_wp_error( $objet ) ) {
			$this->chemins[ $terme ] = array();
			return array();
		}

		$chemin = array( $objet->name );
		foreach ( (array) get_ancestors( $terme, 'product_cat' ) as $ancetre ) {
			$parent = get_term( (int) $ancetre, 'product_cat' );
			if ( $parent && ! is_wp_error( $parent ) ) {
				array_unshift( $chemin, $parent->name );
			}
		}

		$this->chemins[ $terme ] = $chemin;
		return $chemin;
	}

	/**
	 * Les attributs, avec la seule information qui compte : « utilisé pour les
	 * variations » ou non.
	 *
	 * C'est le marchand qui a déjà répondu à la question « déclinaison ou champ
	 * personnalisé ? », en cochant cette case. On lit sa réponse.
	 */
	private function attributs( $produit ) {
		$attributs = array();
		foreach ( (array) $produit->get_attributes() as $attribut ) {
			if ( ! is_object( $attribut ) || ! method_exists( $attribut, 'get_name' ) ) {
				continue;
			}

			$valeurs = array();
			if ( $attribut->is_taxonomy() ) {
				foreach ( (array) $attribut->get_terms() as $terme ) {
					$valeurs[] = $terme->name;
				}
			} else {
				$valeurs = array_map( 'strval', (array) $attribut->get_options() );
			}

			$attributs[] = array(
				'nom'             => wc_attribute_label( $attribut->get_name(), $produit ),
				'pour_variations' => (bool) $attribut->get_variation(),
				'valeurs'         => $valeurs,
				'position'        => (int) $attribut->get_position(),
			);
		}
		return $attributs;
	}

	/**
	 * Les variations d'un produit variable.
	 *
	 * Les variations en brouillon sont écartées : `get_children()` les rend, mais
	 * elles ne sont pas vendables, et les importer donnerait des déclinaisons que
	 * le site ne propose pas.
	 */
	private function variations( $produit ) {
		$variations = array();

		foreach ( (array) $produit->get_children() as $identifiant ) {
			$variation = wc_get_product( (int) $identifiant );
			if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
				continue;
			}
			if ( 'publish' !== get_post_status( (int) $identifiant ) ) {
				continue;
			}

			$variations[] = array(
				'id'            => $variation->get_id(),
				'reference'     => (string) $variation->get_sku(),
				'prix'          => (string) $variation->get_price(),
				'classe_taxe'   => 'parent' === $variation->get_tax_class() ? '' : (string) $variation->get_tax_class(),
				'statut_taxe'   => (string) $variation->get_tax_status(),
				// Comparaison stricte : voir le piège documenté en tête de classe.
				'suit_le_stock' => true === $variation->managing_stock(),
				'stock'         => $variation->get_stock_quantity(),
				'image'         => $this->image( $variation->get_image_id() ),
				'poids'         => (string) $variation->get_weight(),
				'choix'         => $this->choix_de_variation( $variation ),
			);
		}

		return $variations;
	}

	/**
	 * Ce qu'une variation a choisi sur chaque axe, en intitulés lisibles.
	 *
	 * WooCommerce garde des identifiants de terme (« 38 » peut être le slug
	 * « 38 », mais « Bleu nuit » est le slug « bleu-nuit ») : envoyer le slug à
	 * Felar mettrait « bleu-nuit » sur l'étiquette du magasin. Une valeur vide
	 * signifie « au choix du client », et c'est une information — pas un oubli.
	 */
	private function choix_de_variation( $variation ) {
		$choix = array();

		foreach ( (array) $variation->get_attributes() as $taxonomie => $valeur ) {
			$nom     = wc_attribute_label( $taxonomie );
			$lisible = (string) $valeur;

			if ( '' !== $lisible && taxonomy_exists( $taxonomie ) ) {
				$terme = get_term_by( 'slug', $lisible, $taxonomie );
				if ( $terme && ! is_wp_error( $terme ) ) {
					$lisible = $terme->name;
				}
			}

			$choix[ $nom ] = $lisible;
		}

		return $choix;
	}
}
