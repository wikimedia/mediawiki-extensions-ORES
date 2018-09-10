<?php
/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace ORES;

use ResourceLoader;
use ResourceLoaderContext;
use ResourceLoaderFileModule;

/**
 * ResourceLoader module for ext.ores.api
 */
class ResourceLoaderORESAPIModule extends ResourceLoaderFileModule {

	/**
	 * @inheritDoc
	 */
	public function getScript( ResourceLoaderContext $context ) {
		return ResourceLoader::makeConfigSetScript(
				[ 'wgExtOresApiConfig' => $this->getFrontendConfiguraton() ]
			)
			. "\n"
			. parent::getScript( $context );
	}

	/**
	 * Returns an array of configuration for ORES API modules
	 *
	 * @return string[]
	 */
	private function getFrontendConfiguraton() {
		return [
			'wikiId' => ORESService::getWikiID(),
			'baseUrl' => ORESService::getBaseUrl(),
			'apiVersion' => ORESService::API_VERSION,
		];
	}

	public function getDefinitionSummary( ResourceLoaderContext $context ) {
		$summary = parent::getDefinitionSummary( $context );
		$summary[] = [
			'config' => $this->getFrontendConfiguraton(),
		];
		return $summary;
	}

}
