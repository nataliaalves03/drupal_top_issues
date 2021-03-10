<?php

namespace Drupal\issues\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Form\FormStateInterface;


/**
 * Provides a 'Active Issues' block.
 *
 * @Block(
 *   id = "issues_block",
 *   admin_label = @Translation("Active Issues"),
 * )
 */
class IssuesBlock extends BlockBase implements BlockPluginInterface
{

    protected $default_project = "Translation templates for Drupal core";
    protected $default_max_issues = 10;

    /**
     * {@inheritdoc}
     */
    public function build()
    {
        $config = $this->getConfiguration();

        //project name from admin settings
        $project = $this->default_project;
        if (isset($config['project_block_name']) && !empty($config['project_block_name'])) {
            $project = $config['project_block_name'];
        }

        //list project issues
        $results = $this->getIssues(urlencode($project));
        if (empty($results)) return [];

        //format table rows
        $rows = array();
        foreach ($results as $i => $row) {
            $rows[] = [
                ['data' => htmlspecialchars_decode($row['title'])]
            ];
        }

        //format table content
        $content = [];
        $content['message'] = [
            '#markup' => $this->t('Most active issues of the @project', ['@project' => $project])
        ];
        $content['table'] = [
            '#type' => 'table',
            '#header' => [t('Issue title')],
            '#rows' => $rows,
            '#empty' => t('No issues available')
        ];

        return $content;
    }

    /**
     * Get active project issues
     */
    private function getIssues($project = "")
    {
        $contents = [];

        //url to get Drupal projects active issues
        //Todo order by comment_count does not work
        $url = "https://www.drupal.org/project/issues/rss?text="
            . "&projects=" . $project
            . "&status=1&priorities=All&categories=All&order=comment_count&sort=desc";

        try {
            $client = \Drupal::httpClient();
            $request = $client->get($url);
            $status = $request->getStatusCode();
            if ($status == 200) {
                $contents_xml = $request->getBody()->getContents();

                //xml to array using simpleXML extension
                $xml = simplexml_load_string($contents_xml, "SimpleXMLElement", LIBXML_NOCDATA);
                $json = json_encode($xml);
                $contents = json_decode($json, TRUE);

                $contents = $contents['channel']['item'];
            }
        } catch (RequestException $e) {
            \Drupal::logger('issues')->error($e->getMessage());
        }

        //limit max number of issues
        $contents = $this->getMaxIssues($contents);

        return $contents;
    }

    /**
     * Limit number of issues to be displayed
     */
    private function getMaxIssues($contents)
    {
        $config = $this->getConfiguration();
        $max = $this->default_max_issues;
        if (isset($config['maxissues_block_name']) && !empty($config['maxissues_block_name'])) {
            $max = $config['maxissues_block_name'];
        }
        $contents = array_slice($contents, 0, $max);

        return $contents;
    }


    /**
     * {@inheritdoc}
     */
    public function blockForm($form, FormStateInterface $form_state)
    {
        $form = parent::blockForm($form, $form_state);

        $config = $this->getConfiguration();

        //add fields into block configuration - admin settings

        //add project name
        $form['project_block_name'] = [
            '#type' => 'textfield',
            '#required' => TRUE,
            '#title' => $this->t('Project'),
            '#description' => $this->t('Drupal project title to display the active issues'),
            '#default_value' => isset($config['project_block_name']) ? $config['project_block_name'] : '',
        ];

        //add max number of issues
        $form['maxissues_block_name'] = [
            '#type' => 'number',
            '#min' => 1,
            '#max' => 100,
            '#required' => TRUE,
            '#title' => $this->t('Number of issues'),
            '#description' => $this->t('Maximum number of issues to be displayed'),
            '#default_value' => isset($config['maxissues_block_name']) ? $config['maxissues_block_name'] : '',
        ];

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function blockSubmit($form, FormStateInterface $form_state)
    {
        //add project name to configurations
        parent::blockSubmit($form, $form_state);
        $values = $form_state->getValues();
        $this->configuration['project_block_name'] = $values['project_block_name'];
        $this->configuration['maxissues_block_name'] = $values['maxissues_block_name'];
    }

    /**
     * {@inheritdoc}
     */
    public function blockValidate($form, FormStateInterface $form_state)
    {
        //check if project name is not empty
        if (empty($form_state->getValue('project_block_name'))) {
            $form_state->setErrorByName('project_block_name', $this->t('Project title cannot be empty.'));
        }

        //check if max issues is numeric
        if (empty($form_state->getValue('maxissues_block_name')) || !is_numeric($form_state->getValue('maxissues_block_name'))) {
            $form_state->setErrorByName('maxissues_block_name', $this->t('The number of issues must be numeric.'));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheTags()
    {
        //enable caching
        return \Drupal\Core\Cache\Cache::mergeTags(parent::getCacheTags(), ['node_list']);
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheMaxAge()
    {
        // cache disabled = 0
        return 3600;
    }
}
