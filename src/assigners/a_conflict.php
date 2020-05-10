<?php
// a_conflict.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Conflict_AssignmentParser extends AssignmentParser {
    private $remove;
    private $iscontact;
    function __construct(Conf $conf, $aj) {
        parent::__construct("conflict");
        $this->remove = $aj->remove;
        $this->iscontact = $aj->iscontact;
    }
    static function load_conflict_state(AssignmentState $state) {
        if ($state->mark_type("conflict", ["pid", "cid"], "Conflict_Assigner::make")) {
            $result = $state->conf->qe("select paperId, contactId, conflictType from PaperConflict where conflictType!=0 and paperId?a", $state->paper_ids());
            while (($row = $result->fetch_row())) {
                $state->load(["type" => "conflict", "pid" => +$row[0], "cid" => +$row[1], "_ctype" => +$row[2]]);
            }
            Dbl::free($result);
        }
    }
    function load_state(AssignmentState $state) {
        self::load_conflict_state($state);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if (!$state->user->can_administer($prow)
            && !$state->user->privChair
            && !$state->user->act_author($prow)) {
            return "You can’t administer #{$prow->paperId}.";
        } else if (!$this->iscontact
                   && !$state->user->can_administer($prow)
                   && ($whyNot = $state->user->perm_update_paper($prow))) {
            return whyNotText($whyNot);
        } else {
            return true;
        }
    }
    function make_conflict_type($req, AssignmentState $state) {
        if ($this->remove) {
            $s = $this->iscontact ? "=" . CONFLICT_CONTACTAUTHOR : "!=0";
            return [new CountMatcher($s), 0, false];
        } else if ($this->iscontact) {
            return [null, CONFLICT_CONTACTAUTHOR, false];
        } else {
            $cts = strtolower(trim((string) $req["conflict"]));
            $cm = null;
            $error = false;
            $confset = $state->conf->conflict_types();
            if (($colon = strpos($cts, ":")) !== false) {
                $octs = trim(substr($cts, 0, $colon));
                if ($octs === "" || $octs === "any" || $octs === "all") {
                    $cm = new CountMatcher("!=0");
                } else if (($ct = $confset->parse_assignment($octs, Conflict::PLACEHOLDER)) !== false) {
                    $cm = new CountMatcher("=" . $ct);
                } else {
                    $error = true;
                }
                $cts = trim(substr($cts, $colon + 1));
                if ($cts === "") {
                    $cts = "none";
                }
            }
            if ($cts === "") {
                $ct = Conflict::PLACEHOLDER;
            } else if (($ct = $confset->parse_assignment($cts, Conflict::PLACEHOLDER)) === false) {
                $ct = Conflict::PLACEHOLDER;
                $error = true;
            }
            return [$cm, $ct, $error];
        }
    }
    function conflict_type($req, AssignmentState $state) {
        if (!isset($req["_conflict_type"]) || !is_array($req["_conflict_type"])) {
            $req["_conflict_type"] = $this->make_conflict_type($req, $state);
        }
        return $req["_conflict_type"];
    }
    function expand_any_user(PaperInfo $prow, $req, AssignmentState $state) {
        list($matcher, $ct, $error) = $this->conflict_type($req, $state);
        if ($matcher && !$matcher->test(0)) {
            $m = $state->query(["type" => "conflict", "pid" => $prow->paperId]);
            $cids = array_map(function ($x) { return $x["cid"]; }, $m);
            return $state->users_by_id($cids);
        } else {
            return false;
        }
    }
    function allow_user(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        return $contact->contactId != 0;
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $res = $state->remove(["type" => "conflict", "pid" => $prow->paperId, "cid" => $contact->contactId]);
        $admin = $state->user->can_administer($prow);
        list($matcher, $ct, $error) = $this->conflict_type($req, $state);
        assert($this->iscontact
               ? $ct === 0 || $ct === CONFLICT_CONTACTAUTHOR
               : $ct === Conflict::PLACEHOLDER || $ct < CONFLICT_AUTHOR);
        if ($error) {
            return "Bad conflict type.";
        }
        if (!$this->remove
            && !$this->iscontact
            && $ct !== Conflict::PLACEHOLDER
            && !$admin) {
            $ct = Conflict::set_pinned($ct, false);
        }
        $old_ct = empty($res) ? 0 : $res[0]["_ctype"];
        if ((!$this->iscontact
             && Conflict::is_pinned($old_ct)
             && (Conflict::is_author($old_ct)
                 || (!$admin && !Conflict::is_pinned($ct))))
            || ($this->iscontact
                && $ct === 0
                && !Conflict::is_author($old_ct))
            || ($ct === Conflict::PLACEHOLDER
                && Conflict::is_conflicted($old_ct))
            || ($matcher
                && !$matcher->test($old_ct))) {
            $ct = $old_ct;
        }
        if ($ct === Conflict::PLACEHOLDER) {
            $ct = $admin ? Conflict::PINNED : Conflict::GENERAL;
        }
        if ($ct !== 0) {
            $state->add(["type" => "conflict", "pid" => $prow->paperId, "cid" => $contact->contactId, "_ctype" => $ct]);
        }
        return true;
    }
}

class Conflict_Assigner extends Assigner {
    private $ctype;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->ctype = $item->get(false, "_ctype");
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        if ($item->deleted()
            && $item->get(true, "_ctype") >= CONFLICT_CONTACTAUTHOR) {
            $ncontacts = 0;
            foreach ($state->query(["type" => "conflict", "pid" => $item["pid"]]) as $m) {
                if ($m["_ctype"] >= CONFLICT_CONTACTAUTHOR)
                    ++$ncontacts;
            }
            if ($ncontacts === 0) {
                throw new Exception("Each submission must have at least one contact.");
            }
        }
        return new Conflict_Assigner($item, $state);
    }
    static function check_unconflicted($item, AssignmentState $state) {
        $pid = $item["pid"];
        $cid = isset($item["cid"]) ? $item["cid"] : $item["_cid"];
        $cflt = $state->query(["type" => "conflict", "pid" => $pid, "cid" => $cid]);
        if ($cflt && Conflict::is_conflicted($cflt[0]["_ctype"])) {
            $uname = Text::user_html_nolink($state->user_by_id($cid));
            if (isset($item["_override"])
                && $state->user->can_administer($state->prow($pid))) {
                $state->warning("Overriding {$uname} conflict with #{$pid}.");
            } else if (($state->flags & AssignmentState::FLAG_CSV_CONTEXT) !== 0
                       && $state->user->allow_administer($state->prow($pid))) {
                throw new Exception("{$uname} has a conflict with #{$pid}. Set an “override” column to “yes” to assign the " . $item["type"] . " anyway.");
            } else {
                throw new Exception("{$uname} has a conflict with #{$pid}.");
            }
        }
    }

    function unparse_description() {
        return "conflict";
    }
    private function icon($before) {
        $ctype = $this->item->get($before, "_ctype");
        if (Conflict::is_author($ctype)) {
            return review_type_icon(-2);
        } else if (Conflict::is_conflicted($ctype)) {
            return review_type_icon(-1);
        } else {
            return "";
        }
    }
    function unparse_display(AssignmentSet $aset) {
        $t = $aset->user->reviewer_html_for($this->contact);
        if ($this->item->deleted()) {
            $t = '<del>' . $t . ' ' . $this->icon(true) . '</del>';
        } else if (!$this->item->existed()) {
            $t = '<ins>' . $t . ' ' . $this->icon(false) . '</ins>';
        } else {
            $t = $t . ' <del>' . $this->icon(true) . '</del> <ins>' . $this->icon(false) . '</ins>';
        }
        return $t;
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        return [
            "pid" => $this->pid,
            "action" => "conflict",
            "email" => $this->contact->email,
            "name" => $this->contact->name(),
            "conflict" => $aset->conf->conflict_types()->unparse_assignment($this->ctype)
        ];
    }
    function account(AssignmentSet $aset, AssignmentCountSet $deltarev) {
        $aset->show_column("pcconflicts");
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperConflict"] = "write";
    }
    function execute(AssignmentSet $aset) {
        if ($this->ctype) {
            $aset->stage_qe("insert into PaperConflict set paperId=?, contactId=?, conflictType=? on duplicate key update conflictType=values(conflictType)", $this->pid, $this->cid, $this->ctype);
        } else {
            $aset->stage_qe("delete from PaperConflict where paperId=? and contactId=?", $this->pid, $this->cid);
        }
    }
}
