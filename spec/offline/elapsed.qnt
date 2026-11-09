// An offline limit (XP-14, XP-03, MP-4): a node keeps deciding offline only while less than LIMIT has
// passed since its link was lost. Time really passes (real), the wall clock can be set back or forward,
// and the machine can reboot, which restarts the elapsed clock and makes old marks unmeasurable.
//
// ELAPSED = true is YorvoID's way (WithinOfflineLimit with BootTime): measured from the mark on the
// boot's elapsed clock, refused for a mark of another boot. ELAPSED = false measures with the wall clock,
// which must break SAFE, and only by the wall clock being set back (WALL_SAFE_UNLESS_SET_BACK).
module offlineLimit {
  const LIMIT: int
  const MAX_TIME: int
  const ELAPSED: bool

  // How much time has really passed: the property's yardstick, which no clock can change.
  var real: int
  var wall: int
  var elapsed: int
  var boot: int
  // The link's loss: when, by each clock; markBoot is -1 while the link is up.
  var markReal: int
  var markWall: int
  var markElapsed: int
  var markBoot: int
  // The last decision, when it was really made, and whether the wall clock was ever set back.
  var permitted: bool
  var decidedAt: int
  var setBack: bool

  action init = all {
    real' = 0, wall' = 100, elapsed' = 0, boot' = 0,
    markReal' = 0, markWall' = 0, markElapsed' = 0, markBoot' = -1,
    permitted' = false, decidedAt' = 0, setBack' = false,
  }

  action tick = all {
    real < MAX_TIME,
    real' = real + 1, wall' = wall + 1, elapsed' = elapsed + 1,
    boot' = boot, markReal' = markReal, markWall' = markWall, markElapsed' = markElapsed, markBoot' = markBoot, permitted' = permitted, decidedAt' = decidedAt, setBack' = setBack,
  }

  action wallBack(d: int): bool = all {
    wall' = wall - d, setBack' = true,
    real' = real, elapsed' = elapsed, boot' = boot, markReal' = markReal, markWall' = markWall, markElapsed' = markElapsed, markBoot' = markBoot, permitted' = permitted, decidedAt' = decidedAt,
  }

  action wallForward(d: int): bool = all {
    wall' = wall + d,
    real' = real, elapsed' = elapsed, boot' = boot, markReal' = markReal, markWall' = markWall, markElapsed' = markElapsed, markBoot' = markBoot, permitted' = permitted, decidedAt' = decidedAt, setBack' = setBack,
  }

  action reboot = all {
    boot < 2,
    boot' = boot + 1, elapsed' = 0,
    real' = real, wall' = wall, markReal' = markReal, markWall' = markWall, markElapsed' = markElapsed, markBoot' = markBoot, permitted' = permitted, decidedAt' = decidedAt, setBack' = setBack,
  }

  // The link is lost: the mark is taken, once.
  action loseLink = all {
    markBoot == -1,
    markReal' = real, markWall' = wall, markElapsed' = elapsed, markBoot' = boot,
    real' = real, wall' = wall, elapsed' = elapsed, boot' = boot, permitted' = permitted, decidedAt' = decidedAt, setBack' = setBack,
  }

  // The decider is asked: it would allow, so the limit decides.
  action decide = all {
    markBoot != -1,
    permitted' = if (ELAPSED) markBoot == boot and elapsed >= markElapsed and elapsed - markElapsed < LIMIT
                 else wall - markWall < LIMIT,
    decidedAt' = real,
    real' = real, wall' = wall, elapsed' = elapsed, boot' = boot, markReal' = markReal, markWall' = markWall, markElapsed' = markElapsed, markBoot' = markBoot, setBack' = setBack,
  }

  action step = any {
    tick,
    nondet d = 1.to(3).oneOf()
    any { wallBack(d), wallForward(d) },
    reboot,
    loseLink,
    decide,
  }

  // A decision never permits once LIMIT has really passed since the link was lost.
  val SAFE = permitted implies decidedAt - markReal < LIMIT

  // The wall clock's way breaks it only by being set back.
  val WALL_SAFE_UNLESS_SET_BACK = permitted implies (decidedAt - markReal < LIMIT or setBack)
}

module elapsedLimit {
  import offlineLimit(LIMIT = 3, MAX_TIME = 6, ELAPSED = true).*
}

module wallLimit {
  import offlineLimit(LIMIT = 3, MAX_TIME = 6, ELAPSED = false).*
}
