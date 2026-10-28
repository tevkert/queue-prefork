<?php

// An auto_prepend_file that ignores TERM before anything else runs (QueueGatesTest): a real SIG_IGN,
// which an exec keeps.
pcntl_signal(SIGTERM, SIG_IGN);
