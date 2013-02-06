<?php
/**
 * doodlesimple Plugin 1.0: helps to schedule meetings
 *
 * @license	GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @url     http://www.dokuwiki.org/plugin:doodlesimple
 * @author  Helmut Bartz <rentner_09@gmx.de>
 * @author  Robert Rackl <wiki@doogie.de>
 * @author	Jonathan Tsai <tryweb@ichiayi.com>
 * @author  Esther Brunner <wikidesign@gmail.com>
 * @author  Romain Coltel <aorimn@gmail.com>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * Displays a table where users can vote for some predefined choices
 * Syntax:
 * 
 * <pre>
 * <doodlesimple
 *   title="What do you like best?"
 *   voteType="single|multi"
 *   sortedBy="name|time"//default -> name
 *   isOpen="true|false" >//default -> true
 *     * Option 1 
 *     * Option 2 **some wikimarkup** \\ is __allowed__!
 *     * Option 3
 * </doodlesimple>
 * </pre>
 *
 * Only required parameteres are a title and at least one option.
 * 
 * <h3>Vote Type</h3>
 * single     - user can vote for exactly one option (round checkboxes will be shown)
 * multi      - can choose any number of options (square checkboxes will be shown).
 *
 * If isOpen=="false", then no one can vote anymore. The result will still be shown on the page.
 *
 * The doodlesimple's data is saved in '<dokuwiki>/data/meta/title_of_vote.doodle'. The filename is the (masked) title. 
 * This has the advantage that you can move your doodle to another page, without loosing the data.
 * 
 * 
 * derived entirely from doodle2
 * changed compared to doodle2:
 * - No identity verification. 
 * - A possibly existing name will be overwritten with new values
 * - An entry without a choice is ignored
 * - Your entry without a choice, if present, name deleted
 */

class syntax_plugin_doodlesimple extends DokuWiki_Syntax_Plugin 
{
    
    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<doodlesimple\b.*?>.+?</doodlesimple>', $mode, 'plugin_doodlesimple');
    }

    /**
     * Handle the match, parse parameters & choices
     * and prepare everything for the render() method.
     */
    function handle($match, $state, $pos, &$handler) {
        $match = substr($match, 14, -15);              // strip markup (including space after "<doodlesimple ")
        list($parameterStr, $choiceStr) = preg_split('/>/u', $match, 2);

        //----- default parameter settings
        $params = array(
          'title'          => 'Default title',
          'voteType'       => 'single',
        	'sortedBy'	     => 'name',
        	'isOpen'         => TRUE
        );

        //----- parse parameteres into name="value" pairs  
        preg_match_all("/(\w+?)=\"(.*?)\"/", $parameterStr, $regexMatches, PREG_SET_ORDER);
        //debout($parameterStr);
        //debout($regexMatches);
        for ($i = 0; $i < count($regexMatches); $i++) {
            $name  = strtoupper($regexMatches[$i][1]);  // first subpattern: name of attribute in UPPERCASE
            $value = $regexMatches[$i][2];              // second subpattern is value
            if (strcmp($name, "TITLE") == 0) {
                $params['title'] = hsc(trim($value));
            } else
            if (strcmp($name, "VOTETYPE") == 0) {
                if (preg_match('/single|multi/', $value)) {
                    $params['voteType'] = $value;
                }
            } else 
            if (strcmp($name, "SORTEDBY") == 0) {
                if (preg_match('/name|time/', $value)) {
                    $params['sortedBy'] = $value;
                }
            } else 
            	if (strcmp($name, "ISOPEN") == 0) {
                $params['isOpen']= strcasecmp($value, "TRUE") == 0;
            }
        }

        // (If there are no choices inside the <doodle> tag, then doodle's data will be reset.)
        $choices = $this->parseChoices($choiceStr);
        
        $result = array('params' => $params, 'choices' => $choices);
        //debout('handle returns', $result);
        return $result;
    }

    /**
     * parse list of choices
     * explode, trim and encode html entities,
     * empty choices will be skipped.
     */
    function parseChoices($choiceStr) {
        $choices = array();
        preg_match_all('/^   \* (.*?)$/m', $choiceStr, $matches, PREG_PATTERN_ORDER);
        foreach ($matches[1] as $choice) {
            $choice = hsc(trim($choice));
            if (!empty($choice)) {
                $choice = preg_replace('#\\\\\\\\#', '<br />', $choice);       # two(!) backslashes for a newline
                $choice = preg_replace('#\*\*(.*?)\*\*#', '<b>\1</b>', $choice);   # bold
                $choice = preg_replace('#__(.*?)__#', '<u>\1</u>', $choice);   # underscore
                $choice = preg_replace('#//(.*?)//#', '<i>\1</i>', $choice);   # italic
                $choices []= $choice;
            }
        }
        //debout($choices);
        return $choices;
    }

    // ----- these fields will always be initialized at the beginning of the render function
    //       and can then be used in helper functions below.
    public $params    = array();
    public $choices   = array();
    public $doodle    = array();
    public $template  = array();   // output values for doodle_template.php

    /**
     * Read doodle data from file,
     * add new vote if user just submitted one and
     * create output xHTML from template
     */
    function render($mode, &$renderer, $data) {
        if ($mode != 'xhtml') return false;
        
        //debout("render: $mode");        
        global $lang;
        global $auth;
        global $conf; 
        global $ACT;  // action from $_REQUEST['do']
        global $REV;  // to not allow any action if it's an old page        
        global $ID;   // name of current page

        //debout('data in render', $data);

        $this->params    = $data['params'];
        $this->choices   = $data['choices'];
        $this->doodle    = array();
        $this->template  = array();
        
        // prevent caching to ensure the poll results are fresh
        $renderer->info['cache'] = false;

        // ----- read doodle data from file (if there are choices given and there is a file)
        if (count($this->choices) > 0) {
            $this->doodle = $this->readDoodleDataFromFile();
        }


        // ----- FORM ACTIONS (only allowed when showing the most recent version of the page, not when editing) -----
        $formId =  'doodle__form__'.cleanID($this->params['title']);
        if ($ACT == 'show' && $_REQUEST['formId'] == $formId && $REV == false) {
            // ---- cast new vote
            if (!empty($_REQUEST['cast__vote'])) {
                $this->castVote();
            } 
        }
        
        /******** Format of the $doodle array ***********
         * The $doodle array maps fullnames (with html special characters masked) to an array of userData for this vote.
         * Each sub array contains:
         *   'username' loggin name if use was logged in
         *   'choices'  is an (variable length!) array of column indexes where user has voted
         *   'ip'       ip of voting machine
         *   'time'     unix timestamp when vote was casted
         
        
        $doodle = array(
          'Robert' => array(
            'choices'   => array(0, 3),
            'ip'        => '123.123.123.123',
            'time'      => 1284970602
          ),
          'Peter' => array(
            'choices'   => array(),
            'ip'        => '222.122.111.1',
            'time'      > 12849702333
          ),
          'Sabine' => array(
            'choices'   => array(0, 1, 2, 3, 4),
            'ip'        => '333.333.333.333',
            'time'      => 1284970222
          ),
        );
        */
        
        // ---- fill $this->template variable for doodle_template.php (column by column)
        $this->template['title']      = hsc($this->params['title']);
        $this->template['choices']    = $this->choices;
        $this->template['result']     = $this->params['isOpen'] ? $this->getLang('count') : $this->getLang('final_result');
        $this->template['doodleData'] = array();  // this will be filled with some HTML snippets
        $this->template['formId']     = $formId;
        if ($this->params['']) {
            $this->template['msg'] = $this->getLang('poll_');
        }
        
        for($col = 0; $col < count($this->choices); $col++) {
            $this->template['count'][$col] = 0;
            foreach ($this->doodle as $fullname => $userData) {
                if (in_array($col, $userData['choices'])) {
                    $timeLoc = strftime($conf['dformat'], $userData['time']);  // localized time of vote
                	$this->template['doodleData']["$fullname"]['choice'][$col] = 
                        '<td class="centeralign" title="'.$timeLoc.'"><b>+</b></td>';
                    $this->template['count']["$col"]++;
                } else {
                    $this->template['doodleData']["$fullname"]['choice'][$col] = 
                        '<td>&nbsp;</td>';
                }                
            }
        }
                
        // ---- calculates if user is allowed to vote
        $this->template['inputTR'] = $this->getInputTR();
        
        // ----- I am using PHP as a templating engine here.
        //debout("Template", $this->template);
        ob_start();
        include 'doodle_template.php';  // the array $template can be used inside doodle_template.php!
        $doodle_table = ob_get_contents();
        ob_end_clean();
        $renderer->doc .= $doodle_table;
    }

    // --------------- FORM ACTIONS -----------
    /** 
     * ACTION: 
     * cast a new vote 
     * or save a changed vote
     * or delete a vote
     */
    function castVote() {
        $fullname          = hsc(trim($_REQUEST['fullname'])); 
        $selected_indexes  = $_REQUEST['selected_indexes'];  // may not be set when all checkboxes are deseleted.

        if (empty($fullname)) {
            $this->template['msg'] = $this->getLang('dont_have_name');
            return;
        }
        
        if (empty($selected_indexes)) {
            unset($this->doodle["$fullname"]);
            $this->writeDoodleDataToFile();
            $this->template['msg'] = $this->getLang('vote_deleted');
            return;
        }
        
        $this->doodle["$fullname"]['choices'] = $selected_indexes;
        $this->doodle["$fullname"]['time']    = time();
        $this->doodle["$fullname"]['ip']      = $_SERVER['REMOTE_ADDR'];
        $this->writeDoodleDataToFile();
        $this->template['msg'] = $this->getLang('vote_saved');
        
     }
    
    
    // ---------- HELPER METHODS -----------

    /**
     * calculate the input table row:
     * @return   complete <TR> tags for input row and information message
     * May return empty string, if user is not allowed to vote
     *
     * If user is logged in he is always allowed edit his own entry. ("change his mind")
     * If user is logged in and has already voted, empty string will be returned. 
     * If user is not logged in but login is required (auth="user"), then also return '';
     */
    function getInputTR() {
        global $ACT;
        if ($ACT != 'show') return '';
        if (!$this->params['isOpen']) return '';
        
        $fullname = '';
        // build html for tr
        $TR  = '<tr><td class="rightalign"><input type="text" name="fullname" value="" /></td>';
        $c = count($this->choices);
        for($col = 0; $col < $c; $col++) {
            $TR .= '<td class="centeralign">';
            $inputType = ($this->params['voteType'] == 'multi') ? "checkbox" : "radio";
            $TR .= '<input type="'.$inputType.'" name="selected_indexes[]" value="'.$col.'"';
            $TR .= ' />';
            $TR .= '</td>';
        }
        $TR .= '</tr>';
        $TR .= '<tr>';
        $TR .= '<td colspan="'.($c+1).'" class="centeralign">';
        $TR .= '<input type="submit" id="voteButton" value=" '.$this->getLang('btn_vote').' " name="cast__vote" class="button" />';
        $TR .= '</td>';
        $TR .= '</tr>';
        return $TR;
    }
    
    
    /**
     * Loads the serialized doodle data from the file in the metadata directory.
     * If the file does not exist yet, an empty array is returned.
     * @return the $doodle array
     * @see writeDoodleDataToFile()
     */
    function readDoodleDataFromFile() {
        $dfile     = $this->getDoodleFileName();
        $doodle    = array();
        if (file_exists($dfile)) {
            $doodle = unserialize(file_get_contents($dfile));
        }
        //sanitize: $doodle[$fullnmae]['choices'] must be at least an array
        //          This may happen if user deselected all choices
        foreach($doodle as $fullname => $userData) {
            if (!is_array($doodle["$fullname"]['choices'])) {
                $doodle["$fullname"]['choices'] = array();
            }
        }
        
        if (strcmp($this->params['sortedBy'], 'name') == 0) {// case insensitive "natural" sort
            uksort($doodle, 'strnatcasecmp');
        } else {
            uasort($doodle, 'cmpEntryByTime'); 
        }
        //debout("read from $dfile", $doodle);
        return $doodle;
    }
    
    /**
     * serialize the doodles data to a file
     */
    function writeDoodleDataToFile() {
        if (!is_array($this->doodle)) return;
        $dfile = $this->getDoodleFileName();
        if (strcmp($this->params['sortedBy'], 'name') == 0) {// case insensitive "natural" sort
            uksort($this->doodle, 'strnatcasecmp');
        } else {
            uasort($this->doodle, 'cmpEntryByTime'); 
        }
        io_saveFile($dfile, serialize($this->doodle));
        //debout("written to $dfile", $doodle);
        return $dfile;
    }
    
    /**
     * create unique filename for this doodle from its title.
     * (replaces space with underscore etc.)
     */
    function getDoodleFileName() {
        if (empty($this->params['title'])) {
          debout('Doodle must have title.');
          return 'doodle.doodle';
        }
        $dID       = hsc(trim($this->params['title']));
        $dfile     = metaFN($dID, '.doodle');       // serialized doodle data file in meta directory
        return $dfile;        
    }


} // end of class

// ----- static functions

/** compare two doodle entries by the time of vote */
function cmpEntryByTime($a, $b) {
    return strcmp($a['time'], $b['time']);
}


function debout() {
    if (func_num_args() == 1) {
        msg('<pre>'.hsc(print_r(func_get_arg(0), true)).'</pre>');
    } else if (func_num_args() == 2) {
        msg('<h2>'.func_get_arg(0).'</h2><pre>'.hsc(print_r(func_get_arg(1), true)).'</pre>');
    }
    
}

?>
