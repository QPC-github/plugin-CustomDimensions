<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CustomDimensions\tests\Integration\Dimension;

use Piwik\Plugins\CustomDimensions\Dimension\Extraction;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tracker\ActionPageview;
use Piwik\Tracker\Request;

/**
 * @group CustomDimensions
 * @group ExtractionTest
 * @group Extraction
 * @group Dao
 * @group Plugins
 */
class ExtractionTest extends IntegrationTestCase
{

    public function setUp()
    {
        parent::setUp();

        if (!Fixture::siteCreated(1)) {
            Fixture::createWebsite('2014-01-01 00:00:00');
        }
    }

    public function test_toArray()
    {
        $extraction = $this->buildExtraction('url', '.com/(.+)/index');
        $value = $extraction->toArray();

        $this->assertSame(array('dimension' => 'url', 'pattern' => '.com/(.+)/index'), $value);
    }

    public function test_extract_url_withMatch()
    {
        $extraction = $this->buildExtraction('url', '.com/(.+)/index');

        $request = $this->buildRequest();
        $value   = $extraction->extract($request);

        $this->assertSame('test', $value);
    }

    public function test_extract_url_withNoPattern()
    {
        $extraction = $this->buildExtraction('url', 'example');

        $request = $this->buildRequest();
        $value   = $extraction->extract($request);

        $this->assertNull($value);
    }

    public function test_extract_url_withPatternButNoMatch()
    {
        $extraction = $this->buildExtraction('url', 'examplePiwik(.+)');

        $request = $this->buildRequest();
        $value   = $extraction->extract($request);

        $this->assertNull($value);
    }

    public function test_actionName_match()
    {
        $extraction = $this->buildExtraction('action_name', 'My(.+)Title');

        $request = $this->buildRequest();
        $value   = $extraction->extract($request);

        $this->assertSame(' Test ', $value);
    }

    public function test_extract_urlparam()
    {
        $request = $this->buildRequest();

        $value = $this->buildExtraction('urlparam', 'module')->extract($request);
        $this->assertSame('CoreHome', $value);

        $value = $this->buildExtraction('urlparam', 'action')->extract($request);
        $this->assertSame('test', $value);

        $value = $this->buildExtraction('urlparam', 'notExist')->extract($request);
        $this->assertNull($value);
    }

    public function test_extract_withAction_shouldReadValueFromAction_NotFromPassedRequest()
    {
        $request = $this->buildRequest();
        $action = new ActionPageview($request);

        // we create a new empty request here to make sure it actually reads the value from $action and not from $request
        $request = new Request(array());
        $request->setMetadata('Actions', 'action', $action);

        $value = $this->buildExtraction('urlparam', 'module')->extract($request);
        $this->assertSame('CoreHome', $value);

        $value = $this->buildExtraction('action_name', 'My(.+)Title')->extract($request);
        $this->assertSame(' Test ', $value);
    }

    public function test_extract_anyRandomTrackingApiParameter()
    {
        $request = $this->buildRequest();

        $value = $this->buildExtraction('urlref', '/ref(.+)')->extract($request);
        $this->assertSame('errer', $value);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Invald dimension 'anyInvalid' used in an extraction. Available dimensions are: url, urlparam, action_name
     */
    public function test_check_shouldFailWhenInvalidDimensionGiven()
    {
        $this->buildExtraction('anyInvalid', '/ref(.+)')->check();
    }

    /**
     * @dataProvider getInvalidPatterns
     * @expectedException \Exception
     * @expectedExceptionMessage You need to group exactly one part of the regular expression inside round brackets, eg 'index_(.+).html'
     */
    public function test_check_shouldFailWhenInvalidPatternGiven($pattern)
    {
        $this->buildExtraction('url', $pattern)->check();
    }

    public function test_check_shouldNotFailWhenValidCombinationsAreGiven()
    {
        $this->buildExtraction('url', 'index_(+).html')->check();
        $this->buildExtraction('action_name', 'index_(+).html')->check();
        $this->buildExtraction('url', '')->check(); // empty value is allowed
        $this->buildExtraction('urlparam', 'index')->check(); // does not have to contain brackets
    }

    public function getInvalidPatterns()
    {
        return array(
            array('index.html'),
            array('index.(html'),
            array('index.)html'),
            array('index.)(html'),
            array('index.)(.+)html'),
        );
    }

    private function buildRequest()
    {
        $url = 'http://www.example.com/test/index.php?idsite=54&module=CoreHome&action=test';
        $referrer = 'http://www.example.com/referrer';
        $actionName = 'My Test Title';

        return new Request(array('idsite' => 1, 'url' => $url, 'action_name' => $actionName, 'urlref' => $referrer));
    }

    private function buildExtraction($dimension, $pattern)
    {
        return new Extraction($dimension, $pattern);
    }
}