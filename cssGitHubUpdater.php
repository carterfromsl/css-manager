<?php

class cssGitHubUpdater {
    private $slug;
    private $pluginData;
    private $githubAPIResult;
    private $pluginFile;
    private $accessToken;

    public function __construct($pluginFile) {
        $this->pluginFile = $pluginFile;
        $this->slug = plugin_basename($pluginFile);
        $this->accessToken = ''; // Add a GitHub personal access token here if the repository is private.

        // Set up WordPress hooks.
        add_filter("pre_set_site_transient_update_plugins", [$this, "setPluginUpdate"]);
        add_filter("plugins_api", [$this, "setPluginInfo"], 10, 3);
        add_filter("upgrader_post_install", [$this, "postInstall"], 10, 3);

        // Get plugin data from the main plugin file.
        $this->pluginData = get_plugin_data($this->pluginFile);
    }

    private function getRepositoryInfo() {
        if (is_null($this->githubAPIResult)) {
            $url = "https://api.github.com/repos/carterfromsl/css-manager/releases/latest";

            $args = [
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress',
                ],
            ];

            if (!empty($this->accessToken)) {
                $args['headers']['Authorization'] = "token {$this->accessToken}";
            }

            $response = wp_remote_get($url, $args);
            if (is_wp_error($response)) {
                return false;
            }

            $this->githubAPIResult = json_decode(wp_remote_retrieve_body($response));
        }

        return $this->githubAPIResult;
    }

    public function setPluginUpdate($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $repoInfo = $this->getRepositoryInfo();
        if ($repoInfo && version_compare($this->pluginData['Version'], $repoInfo->tag_name, '<')) {
            $pluginSlug = $this->slug;
            $transient->response[$pluginSlug] = (object)[
                'slug' => $pluginSlug,
                'new_version' => $repoInfo->tag_name,
                'url' => $repoInfo->html_url,
                'package' => $repoInfo->zipball_url,
            ];
        }

        return $transient;
    }

    public function setPluginInfo($false, $action, $response) {
        $repoInfo = $this->getRepositoryInfo();
        if (isset($response->slug) && $response->slug === $this->slug) {
            $response->last_updated = $repoInfo->published_at;
            $response->slug = $this->slug;
            $response->plugin_name = $this->pluginData['Name'];
            $response->version = $repoInfo->tag_name;
            $response->author = $this->pluginData['AuthorName'];
            $response->homepage = $this->pluginData['PluginURI'];
            $response->sections = [
                'description' => $this->pluginData['Description'],
                'changelog' => $repoInfo->body,
            ];
            $response->download_link = $repoInfo->zipball_url;
        }

        return $response;
    }

    public function postInstall($true, $hook_extra, $result) {
        global $wp_filesystem;
        $pluginFolder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname($this->slug);
        $wp_filesystem->move($result['destination'], $pluginFolder);
        $result['destination'] = $pluginFolder;

        if (isset($this->pluginData['Version'])) {
            $this->pluginData = get_plugin_data($this->pluginFile);
        }

        return $result;
    }
}
