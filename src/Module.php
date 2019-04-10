<?php
declare(strict_types=1);

/**
 * See LICENSE.md file for further details.
 */
namespace MagicSunday\Webtrees\PedigreeChart;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Exceptions\IndividualAccessDeniedException;
use Fisharebest\Webtrees\Exceptions\IndividualNotFoundException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\PedigreeChartModule as WebtreesPedigreeChartModule;
use Fisharebest\Webtrees\Services\ChartService;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\PedigreeChart\Traits\UtilityTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Pedigree chart module class.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/ancestral-fan-chart/
 */
class Module extends WebtreesPedigreeChartModule implements ModuleCustomInterface
{
    use UtilityTrait;

    /**
     * @var string
     */
    public const CUSTOM_AUTHOR = 'Rico Sonntag';

    /**
     * @var string
     */
    public const CUSTOM_VERSION = '1.0';

    /**
     * @var string
     */
    public const CUSTOM_WEBSITE = 'https://github.com/magicsunday/webtrees-pedigree-chart';

    /**
     * The configuration instance.
     *
     * @var Config
     */
    private $config;

    /**
     * Constructor.
     *
     * @param string $moduleDirectory The module base directory
     */
    public function __construct(string $moduleDirectory)
    {
        $this->moduleDirectory = $moduleDirectory;
    }

    /**
     * @inheritDoc
     *
     * @throws IndividualNotFoundException
     * @throws IndividualAccessDeniedException
     */
    public function getChartAction(
        ServerRequestInterface $request,
        Tree $tree,
        UserInterface $user,
        ChartService $chart_service
    ): ResponseInterface {
        $this->config = new Config($request);
        $xref         = $request->getQueryParams()['xref'];
        $individual   = Individual::getInstance($xref, $tree);

        if ($individual === null) {
            throw new IndividualNotFoundException();
        }

        Auth::checkIndividualAccess($individual);
        Auth::checkComponentAccess($this, 'chart', $tree, $user);

        return $this->viewResponse(
            $this->name() . '::chart',
            [
                'title'       => $this->getPageTitle($individual),
                'moduleName'  => $this->name(),
                'individual'  => $individual,
                'tree'        => $tree,
                'config'      => $this->config,
                'chartParams' => json_encode($this->getChartParameters($individual)),
            ]
        );
    }

    /**
     * Returns the page title.
     *
     * @param Individual $individual The individual used in the curret chart
     *
     * @return string
     */
    private function getPageTitle(Individual $individual): string
    {
        $title = I18N::translate('Pedigree chart');

        if ($individual && $individual->canShowName()) {
            $title = I18N::translate('Pedigree chart of %s', $individual->fullName());
        }

        return $title;
    }

    /**
     * Collects and returns the required chart data.
     *
     * @param Individual $individual The individual used to gather the chart data
     *
     * @return string[]
     */
    private function getChartParameters(Individual $individual): array
    {
        return [
            'rtl'            => I18N::direction() === 'rtl',
            'defaultColor'   => $this->getColor(),
            'fontColor'      => $this->getChartFontColor(),
            'generations'    => $this->config->getGenerations(),
            'showEmptyBoxes' => $this->config->getShowEmptyBoxes(),
            'individualUrl'  => $this->getIndividualRoute(),
            'data'           => $this->buildJsonTree($individual),
            'labels'         => [
                'zoom' => I18N::translate('Use Ctrl + scroll to zoom in the view'),
                'move' => I18N::translate('Move the view with two fingers'),
            ],
        ];
    }

    /**
     * Returns the URL of the highlight image of an individual.
     *
     * @param Individual $individual The current individual
     *
     * @return string
     */
    private function getIndividualImage(Individual $individual): string
    {
        if ($individual->canShow()
            && $individual->tree()->getPreference('SHOW_HIGHLIGHT_IMAGES')
        ) {
            $mediaFile = $individual->findHighlightedMediaFile();

            if ($mediaFile !== null) {
                return $mediaFile->imageUrl(250, 250, 'crop');
            }
        }

        return '';
    }

    /**
     * Get the individual data required for display the chart.
     *
     * @param Individual $individual The start person
     * @param int        $generation The generation the person belongs to
     *
     * @return array
     */
    private function getIndividualData(Individual $individual, int $generation): array
    {
        $fullName        = $this->unescapedHtml($individual->fullName());
        $alternativeName = $this->unescapedHtml($individual->alternateName());

        return [
            'id'              => 0,
            'xref'            => $individual->xref(),
            'generation'      => $generation,
            'name'            => $fullName,
            'alternativeName' => $alternativeName,
            'isAltRtl'        => $this->isRtl($alternativeName),
            'thumbnail'       => $this->getIndividualImage($individual),
            'sex'             => $individual->sex(),
            'born'            => $individual->getBirthDate()->minimumDate()->format('%d.%m.%Y'),
            'died'            => $individual->getDeathDate()->minimumDate()->format('%d.%m.%Y'),
            'color'           => $this->getColor($individual),
            'colors'          => [[], []],
        ];
    }

    /**
     * Recursively build the data array of the individual ancestors.
     *
     * @param null|Individual $individual The start person
     * @param int             $generation The current generation
     *
     * @return array
     */
    private function buildJsonTree(Individual $individual = null, int $generation = 1): array
    {
        // Maximum generation reached
        if (($individual === null) || ($generation > $this->config->getGenerations())) {
            return [];
        }

        $data   = $this->getIndividualData($individual, $generation);
        $family = $individual->primaryChildFamily();

        if ($family === null) {
            return $data;
        }

        // Recursively call the method for the parents of the individual
        $fatherTree = $this->buildJsonTree($family->husband(), $generation + 1);
        $motherTree = $this->buildJsonTree($family->wife(), $generation + 1);

        // Add array of child nodes
        if ($fatherTree) {
            $data['children'][] = $fatherTree;
        }

        if ($motherTree) {
            $data['children'][] = $motherTree;
        }

        return $data;
    }
}
