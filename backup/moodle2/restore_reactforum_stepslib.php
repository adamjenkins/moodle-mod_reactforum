<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package    mod_reactforum
 * @subpackage backup-moodle2
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define all the restore steps that will be used by the restore_reactforum_activity_task
 */

/**
 * Structure step to restore one reactforum activity
 */
class restore_reactforum_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('reactforum', '/activity/reactforum');
        if ($userinfo) {
            $paths[] = new restore_path_element('reactforum_discussion', '/activity/reactforum/discussions/discussion');
            $paths[] = new restore_path_element('reactforum_post', '/activity/reactforum/discussions/discussion/posts/post');
            $paths[] = new restore_path_element('reactforum_tag', '/activity/reactforum/poststags/tag');
            $paths[] = new restore_path_element('reactforum_discussion_sub', '/activity/reactforum/discussions/discussion/discussion_subs/discussion_sub');
            $paths[] = new restore_path_element('reactforum_rating', '/activity/reactforum/discussions/discussion/posts/post/ratings/rating');
            $paths[] = new restore_path_element('reactforum_subscription', '/activity/reactforum/subscriptions/subscription');
            $paths[] = new restore_path_element('reactforum_digest', '/activity/reactforum/digests/digest');
            $paths[] = new restore_path_element('reactforum_read', '/activity/reactforum/readposts/read');
            $paths[] = new restore_path_element('reactforum_track', '/activity/reactforum/trackedprefs/track');

            $paths[] = new restore_path_element('reactforum_reactions', '/activity/reactforum/reactions/reaction');
            $paths[] = new restore_path_element('reactforum_user_reactions', '/activity/reactforum/reactions/reaction/user_reactions/user_reaction');
        }

        // Return the paths wrapped into standard activity structure
        return $this->prepare_activity_structure($paths);
    }

    protected function process_reactforum($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        // Any changes to the list of dates that needs to be rolled should be same during course restore and course reset.
        // See MDL-9367.
        $data->assesstimestart = $this->apply_date_offset($data->assesstimestart);
        $data->assesstimefinish = $this->apply_date_offset($data->assesstimefinish);
        if ($data->scale < 0) { // scale found, get mapping
            $data->scale = -($this->get_mappingid('scale', abs($data->scale)));
        }

        $newitemid = $DB->insert_record('reactforum', $data);
        $this->apply_activity_instance($newitemid);

        // Add current enrolled user subscriptions if necessary.
        $data->id = $newitemid;
        $ctx = context_module::instance($this->task->get_moduleid());
        reactforum_instance_created($ctx, $data);
    }

    protected function process_reactforum_discussion($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->reactforum = $this->get_new_parentid('reactforum');
        $data->timestart = $this->apply_date_offset($data->timestart);
        $data->timeend = $this->apply_date_offset($data->timeend);
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->groupid = $this->get_mappingid('group', $data->groupid);
        $data->usermodified = $this->get_mappingid('user', $data->usermodified);

        $newitemid = $DB->insert_record('reactforum_discussions', $data);
        $this->set_mapping('reactforum_discussion', $oldid, $newitemid);
    }

    protected function process_reactforum_post($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->discussion = $this->get_new_parentid('reactforum_discussion');
        $data->userid = $this->get_mappingid('user', $data->userid);
        // If post has parent, map it (it has been already restored)
        if (!empty($data->parent)) {
            $data->parent = $this->get_mappingid('reactforum_post', $data->parent);
        }

        $newitemid = $DB->insert_record('reactforum_posts', $data);
        $this->set_mapping('reactforum_post', $oldid, $newitemid, true);

        // If !post->parent, it's the 1st post. Set it in discussion
        if (empty($data->parent)) {
            $DB->set_field('reactforum_discussions', 'firstpost', $newitemid, array('id' => $data->discussion));
        }
    }

    protected function process_reactforum_tag($data) {
        $data = (object)$data;

        if (!core_tag_tag::is_enabled('mod_reactforum', 'reactforum_posts')) { // Tags disabled in server, nothing to process.
            return;
        }

        $tag = $data->rawname;
        if (!$itemid = $this->get_mappingid('reactforum_post', $data->itemid)) {
            // Some orphaned tag, we could not find the restored post for it - ignore.
            return;
        }

        $context = context_module::instance($this->task->get_moduleid());
        core_tag_tag::add_item_tag('mod_reactforum', 'reactforum_posts', $itemid, $context, $tag);
    }

    protected function process_reactforum_rating($data) {
        global $DB;

        $data = (object)$data;

        // Cannot use ratings API, cause, it's missing the ability to specify times (modified/created)
        $data->contextid = $this->task->get_contextid();
        $data->itemid    = $this->get_new_parentid('reactforum_post');
        if ($data->scaleid < 0) { // scale found, get mapping
            $data->scaleid = -($this->get_mappingid('scale', abs($data->scaleid)));
        }
        $data->rating = $data->value;
        $data->userid = $this->get_mappingid('user', $data->userid);

        // We need to check that component and ratingarea are both set here.
        if (empty($data->component)) {
            $data->component = 'mod_reactforum';
        }
        if (empty($data->ratingarea)) {
            $data->ratingarea = 'post';
        }

        $newitemid = $DB->insert_record('rating', $data);
    }

    protected function process_reactforum_subscription($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->reactforum = $this->get_new_parentid('reactforum');
        $data->userid = $this->get_mappingid('user', $data->userid);

        // Create only a new subscription if it does not already exist (see MDL-59854).
        if ($subscription = $DB->get_record('reactforum_subscriptions',
                array('reactforum' => $data->reactforum, 'userid' => $data->userid))) {
            $this->set_mapping('reactforum_subscription', $oldid, $subscription->id, true);
        } else {
            $newitemid = $DB->insert_record('reactforum_subscriptions', $data);
            $this->set_mapping('reactforum_subscription', $oldid, $newitemid, true);
        }

    }

    protected function process_reactforum_discussion_sub($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->discussion = $this->get_new_parentid('reactforum_discussion');
        $data->reactforum = $this->get_new_parentid('reactforum');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('reactforum_discussion_subs', $data);
        $this->set_mapping('reactforum_discussion_sub', $oldid, $newitemid, true);
    }

    protected function process_reactforum_digest($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->reactforum = $this->get_new_parentid('reactforum');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('reactforum_digests', $data);
    }

    protected function process_reactforum_read($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->reactforumid = $this->get_new_parentid('reactforum');
        $data->discussionid = $this->get_mappingid('reactforum_discussion', $data->discussionid);
        $data->postid = $this->get_mappingid('reactforum_post', $data->postid);
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('reactforum_read', $data);
    }

    protected function process_reactforum_track($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->reactforumid = $this->get_new_parentid('reactforum');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('reactforum_track_prefs', $data);
    }

    protected function process_reactforum_reactions($data)
    {
        global $DB;

        $data = (object)$data;
        $oldID = $data->id;

        if ($data->reactforum_id > 0) {
            $data->reactforum_id = $this->get_new_parentid('reactforum');
        }

        if ($data->discussion_id > 0) {
            $data->discussion_id = $this->get_mappingid('reactforum_discussion', $data->discussion_id);
        }

        $newItemID = $DB->insert_record('reactforum_reactions', $data);

        $this->set_mapping('reactforum_reactions', $oldID, $newItemID, true);
    }

    protected function process_reactforum_user_reactions($data)
    {
        global $DB;

        $data = (object)$data;
        $oldID = $data->id;

        $data->reaction_id = $this->get_new_parentid('reactforum_reactions');
        $data->post_id = $this->get_mappingid('reactforum_post', $data->post_id);
        $data->user_id = $this->get_mappingid('user', $data->user_id);

        $DB->insert_record('reactforum_user_reactions', $data);
    }

    protected function after_execute() {
        // Add reactforum related files, no need to match by itemname (just internally handled context)
        $this->add_related_files('mod_reactforum', 'intro', null);

        // Add post related files, matching by itemname = 'reactforum_post'
        $this->add_related_files('mod_reactforum', 'post', 'reactforum_post');
        $this->add_related_files('mod_reactforum', 'attachment', 'reactforum_post');

        $this->add_related_files('mod_reactforum', 'reactions', 'reactforum_reactions');
    }

    protected function after_restore() {
        global $DB;

        // If the reactforum is of type 'single' and no discussion has been ignited
        // (non-userinfo backup/restore) create the discussion here, using reactforum
        // information as base for the initial post.
        $reactforumid = $this->task->get_activityid();
        $reactforumrec = $DB->get_record('reactforum', array('id' => $reactforumid));
        if ($reactforumrec->type == 'single' && !$DB->record_exists('reactforum_discussions', array('reactforum' => $reactforumid))) {
            // Create single discussion/lead post from reactforum data
            $sd = new stdClass();
            $sd->course   = $reactforumrec->course;
            $sd->reactforum    = $reactforumrec->id;
            $sd->name     = $reactforumrec->name;
            $sd->assessed = $reactforumrec->assessed;
            $sd->message  = $reactforumrec->intro;
            $sd->messageformat = $reactforumrec->introformat;
            $sd->messagetrust  = true;
            $sd->mailnow  = false;
            $sdid = reactforum_add_discussion($sd, null, null, $this->task->get_userid());
            // Mark the post as mailed
            $DB->set_field ('reactforum_posts','mailed', '1', array('discussion' => $sdid));
            // Copy all the files from mod_foum/intro to mod_reactforum/post
            $fs = get_file_storage();
            $files = $fs->get_area_files($this->task->get_contextid(), 'mod_reactforum', 'intro');
            foreach ($files as $file) {
                $newfilerecord = new stdClass();
                $newfilerecord->filearea = 'post';
                $newfilerecord->itemid   = $DB->get_field('reactforum_discussions', 'firstpost', array('id' => $sdid));
                $fs->create_file_from_storedfile($newfilerecord, $file);
            }
        }
    }
}
