<?php
# This is a Powershell port of the ssh-config node module https://github.com/dotnil/ssh-config



class ConfigNode {
    public $Before;
    public $After;
    public $Type;
    public $Content;
    public $Param;
    public $Separator;
    public $Value;
    public $Quoted;
    public $Config;
}

class SshConfig {
    # Regexes used in parsing
    const RE_SPACE = "/\s/";
    const RE_LINE_BREAK = "/\r|\n/";
    const RE_SECTION_DIRECTIVE = "/^(Host|Match)$/";
    const RE_QUOTED = "/^(\")(.*)\1$/";

    public $Nodes = [];

    public function removeHost($sshHost) {
        $result = $this->find($sshHost);

        if ($result) {
            $key = array_search($result, $this->Nodes);
            unset($this->Nodes[$key]);
        }
    }

    public function add($opts) {
        $config = $this;
        $configWas = $this;
        $indent = "  ";

        foreach ($this->Nodes as $line) {
            if (preg_match(self::RE_SECTION_DIRECTIVE, $line->Param)) {
                foreach ($line->Config->Nodes as $subline) {
                    if ($subline->Before) {
                        $indent = $subline->Before;
                        break;
                    }
                }
            }
        }

        foreach ($opts as $key => $value) {
            $line = new ConfigNode();
            $line->Type = 'Directive';
            $line->Param = $key;
            $line->Separator = " ";
            $line->Value = $value;
            $line->Before = "";
            $line->After = "\n";

            if (preg_match(self::RE_SECTION_DIRECTIVE, $key)) {
                $config = $configWas;

                # Make sure we insert before any wildcard lines.
                # find the index of the first wildcard
                $index = 0;

                foreach($config->Nodes as $node) {
                    if (strpos($node->Value, '*') > -1 || strpos($node->Value, '?') > -1) {
                        # Found a wildcard. Make sure we insert before this.
                        break;
                    }
                    $index++;
                }

                if (count($config->Nodes) == 0) {
                    $config->Nodes[] = $line;
                }
                else {
                    array_splice($config->Nodes, $index, 0, $line);
                }

                $config = $line->Config = new SshConfig();
            }
            else {
                $line->Before = $indent;
                $config->Nodes[] = $line;
            }
        }

        $config->Nodes[count($config->Nodes) - 1]->After .= "\n";
    }

    public function find($sshHost) {
        foreach($this->Nodes as $node) {
            if ($node->Type == 'Directive' && $node->Param == 'Host' && $node->Value == $sshHost) {
                return $node;
            }
        }

        return null;
    }

    public function stringify() {
        $output = '';

        $formatter = function($node) use(&$formatter) {
            $output .= $node->Before;

            if ($node->Type == 'Comment') {
                $output .= $node->Content;
            }
            elseif ($node->Type == 'Directive') {
                $str = "";

                if ($node->Quoted || ($node->Param == "IdentityFile" && preg_match(self::RE_SPACE, $node.Value))) {
                    $str = $node->Param . $node->Separator . '"' . $node->Value . '"';
                }
                else {
                    $str = $node->Param . $node->Separator . $node->Value;
                }
                $output .= $str;
            }

            $output .= $node->After;

            if ($node->Config) {
                foreach ($node->Config->Nodes as $child) {
                    $formatter($child);
                }
            }
        };

        foreach ($this->Nodes as $node) {
            $formatter($node);
        }

        return $output;
    }

    public function Compute($sshHost) {
        $result = [];

        $setProperty = function($name, $value) use(&$result) {

            if ($name == "IdentityFile") {
                if (array_key_exists($name, $result)) {
                    $result[$name] = [$value];
                }
                else {
                    $result[$name][] = $value;
                }
            }
            elseif (!isset($result[$name])) {
                $result[$name] = $value;
            }
        };

        $foundHost = false;

        foreach($this->Nodes as $node) {
            if ($node->Type != "Directive") {
                continue;
            }
            elseif ($node->Param == "Host") {
                if($node->Value == $sshHost) {
                    $foundHost = $true;
                }

                if(testGlob($node->Value, $sshHost)) {
                    $setProperty($node->Param, $node->Value);

                    foreach($node.Config.Nodes as $childNode) {
                        if($childNode->Type == "Directive") {
                            $setProperty($childNode->Param, $childNode->Value);
                        }
                    }
                }
            }
            elseif ($node->Param == "Match") {
                # no op
            }
            else {
               $setProperty($node->Param, $node->Value);
            }
        }

        if($foundHost) {
            return $result;
        }
        else {
            return null;
        }
    }
}

function parseSshConfig($str) {
    $config = new SshConfig();
    $rootConfig = $config;

    $context = [
        'count' => 0,
        'char' => null,
    ];

    $next = function() use(&$context) {
        # Force string instead of char
        return ($str[$context['count']++]);
    };

    $space = function() use(&$context, $next) {
        $spaces = "";
        while ($context['char'] && preg_match(ConfigParser::RE_SPACE, $context['char'])) {
            $spaces .= $context['char'];
            $context['char'] = $next();
        }
        return $spaces;
    };

    $linebreak = function() use(&$context, $next) {
        $breaks = "";
        while ($context['char'] && preg_match(ConfigParser::RE_LINE_BREAK, $context['char'])) {
            $breaks .= $context['char'];
            $context['char'] = $next();
        }
        return $breaks;
    };

    $option = function() use (&$context, $next) {
        $opt = "";

        while ($context['char'] && $context['char'] != " " && $context['char'] != "=") {
            $opt .= $context['char'];
            $context['char'] = $next();
        }

        return $opt;
    };

    $separator = function() use (&$context, $next, $space) {
        $sep = $space();

        if ($context['char'] == "=") {
            $sep .= $context['char'];
            $context['char'] = $next();
        }

        return $sep + ($space());
    };

    $value = function() use(&$context, $next) {
        $val = "";

        while ($context['char'] && !preg_match(ConfigParser::RE_LINE_BREAK, $context['char'])) {
            $val .= $context['char'];
            $context['char']= $next();
        }

        return trim($val);
    };

    $comment = function() use (&$context) {
        $type = 'Comment';
        $content = "";
        while ($context['char'] && !preg_match(ConfigParser::RE_LINE_BREAK, $context['char'])) {
            $content .= $context['char'];
            $context['char']= $next();
        }
        $node = new ConfigNode();
        $node->Type = $type;
        $node->Content = $content;
        return $node;
    };

    $directive = function() use($option, $separator, $value) {
        $node = new ConfigNode();
        $node->Type = 'Directive';
        $node->Param = $option();
        $node->Separator = $separator();
        $node->Value = $value();
        return $node;
    };

    $line = function() use ($space, $comment, $directive, $linebreak) {
        $before = $space();
        $node = null;

        if ($context['char'] == "#") {
            $node = $comment();
        }
        else {
            $node = $directive();
        }

        $after = $linebreak();

        $node->Before = $before;
        $node->After = $after;

        if ($node->Value && preg_match(ConfigParser::RE_QUOTED, $node->Value)) {
            $node->Value = preg_replace(ConfigParser::RE_QUOTED, '$2', $node->Value);
            $node->Quoted = $true;
        }

        return $node;
    };

    # Start the process by getting the first character.
    $context['char'] = $next();

    while ($context['char']) {
        $node = $line();
        if ($node->Type == 'Directive' && preg_match(ConfigParser::RE_SECTION_DIRECTIVE, $node->Param)) {
            $config = $rootConfig;
            $config->Nodes[] = $node;
            $config = $node->Config = new SshConfig();
        }
        else {
            $config->Nodes[] = $node;
        }
    }

    return $rootConfig;
}

/*
$script:Splitter = [Regex]::new("[,\s]+")

function testGlob($patternList, $str)  {
    $patterns = $script:Splitter.Split($patternList) | Sort-Object { $_.StartsWith("!") } -Descending

    foreach ($pattern in $patterns) {
        $negate = $pattern[0] -eq '!'

        if ($negate) {
            $pattern = $pattern.Substring(1)
        }

        $pattern = $pattern.Replace(".", "\.").Replace("*", ".*").Replace("?", ".?")
        $result = [Regex]::new('^(?:' + $pattern + ')$').IsMatch($str)

        if ($negate -and $result) {
            return $false;
        }

        if ($result) {
            return $true;
        }
    }

    return $false;
}
*/