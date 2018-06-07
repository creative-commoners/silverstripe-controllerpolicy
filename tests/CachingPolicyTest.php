<?php

class CachingPolicyTest extends FunctionalTest
{

    private $configCachingPolicy = array(
        'CachingPolicy' => array(
            'class' => 'CachingPolicy',
            'properties' => array(
                'cacheAge' => '999',
                'vary' => 'X-EyeColour'
            )
        )
    );

    public function makeRequest($config, $controller, $url)
    {
        Injector::inst()->load($config);

        // Just doing the following in the test results in two disparate ControllerPolicyRequestFilters: one for the
        // controller, and another for the RequestProcessor - even though it should be injected as a singleton. This
        // prevents us from actually testing anything because our policy is wiped out (am I missing something?).
        // $response = $this->get('CachingPolicy_Controller/test');

        // Instead construct the request pipeline manually. It's ugly, but it works.
        $filter = Injector::inst()->get('ControllerPolicyRequestFilter');
        $processor = Injector::inst()->get('RequestProcessor');
        $processor->setFilters(array($filter));

        // Excercise the controller.
        $controller = Injector::inst()->create($controller);
        $controller->setRequestFilter($filter);
        $controller->extend('onAfterInit');
        $request = new SS_HTTPRequest('GET', 'CachingPolicy_Controller/test');
        $response = new SS_HTTPResponse();
        $processor->postRequest($request, $response, DataModel::inst());

        return $response;
    }

    public function setUp()
    {
        parent::setUp();
        Injector::nest();
    }

    public function tearDown()
    {
        Injector::unnest();
        parent::tearDown();
    }

    public function testConfigured()
    {
        $response = $this->makeRequest(
            $this->configCachingPolicy,
            'CachingPolicy_Controller',
            'CachingPolicy_Controller/test'
        );

        $directives = explode(',', $response->getHeader('Cache-Control'));
        $directives = array_map('trim', $directives);

        $this->assertCount(4, $directives);
        $this->assertContains('max-age=999', $directives);
        $this->assertContains('must-revalidate', $directives);
        $this->assertContains('no-cache', $directives);
        $this->assertContains('no-store', $directives);

        $this->assertEquals(
            'X-EyeColour',
            $response->getHeader('Vary'),
            'Header appears as configured'
        );
    }

    public function testCallbackOverride()
    {
        $response = $this->makeRequest(
            $this->configCachingPolicy,
            'CallbackCachingPolicy_Controller',
            'CallbackCachingPolicy_Controller/test'
        );

        $directives = explode(',', $response->getHeader('Cache-Control'));
        $directives = array_map('trim', $directives);

        $this->assertCount(4, $directives);
        $this->assertContains('max-age=1001', $directives);
        $this->assertContains('must-revalidate', $directives);
        $this->assertContains('no-cache', $directives);
        $this->assertContains('no-store', $directives);

        $this->assertEquals(
            'X-HeightWeight',
            $response->getHeader('Vary'),
            'Controller\'s getVary() overrides the configuration'
        );
        $this->assertEquals(
            $response->getHeader('Last-Modified'),
            HTTP::gmt_date('5000'),
            'Controller\'s getModificationTimestamp overrides the HTTP::$modification_date'
        );
    }

    public function testUnrelated()
    {
        $response = $this->makeRequest(
            $this->configCachingPolicy,
            'Unrelated_Controller',
            'Unrelated_Controller/test'
        );

        $this->assertNull($response->getHeader('Cache-Control'), 'Headers on unrelated controller are unaffected');
        $this->assertNull($response->getHeader('Vary'), 'Headers on unrelated controller are unaffected');
    }

    public function testModificationDateFromDataObjects()
    {

        // Trigger updates to HTTP::$modification_date.
        new DataObject(array('LastEdited'=>'1970-01-01 00:02'));
        new DataObject(array('LastEdited'=>'1970-01-01 00:01'));
        new DataObject(array('LastEdited'=>'1970-01-01 00:03'));

        $response = $this->makeRequest(
            $this->configCachingPolicy,
            'CachingPolicy_Controller',
            'CachingPolicy_Controller/test'
        );

        $this->assertEquals(
            $response->getHeader('Last-Modified'),
            HTTP::gmt_date(strtotime('1970-01-01 00:03')),
            'Most recent LastEdited value prevails over the older ones'
        );
    }
}

class Unrelated_Controller extends Controller implements TestOnly
{

    private static $allowed_actions = array(
        'test'
    );

    public function test()
    {
        return 'Hello world!';
    }
}

class CachingPolicy_Controller extends Controller implements TestOnly
{

    private static $dependencies = array(
        'Policies' => '%$CachingPolicy'
    );

    private static $allowed_actions = array(
        'test'
    );

    public function test()
    {
        return 'Hello world!';
    }
}

class CallbackCachingPolicy_Controller extends CachingPolicy_Controller
{

    public function getCacheAge($age)
    {
        return '1001';
    }

    public function getVary($vary)
    {
        return 'X-HeightWeight';
    }

    public function getModificationTimestamp()
    {
        return '5000';
    }
}
