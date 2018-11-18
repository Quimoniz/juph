/* vim: set syntax=javascript: */
var searchField;
var searchListWrapper;
var audioPlayer;
var audioCaption;
var playlistWrapper;
var sessionPlaylist;
var playlistEle;
var playlistObj;
var BODY;
var contextMenu;
var sessionId;
var userAgent;
var secondPane;
var ajax;
var currentTracklist = undefined;
function init()
{
  audioPlayer = document.getElementById("audio_player");
  playlistWrapper = document.getElementById("playlist_wrapper");
  playlistObj = new PlaylistClass();
  playlistObj.assumePlaylist();
  audioPlayer.addEventListener("wheel", playlistObj.onwheel);
  audioPlayer.addEventListener("play", playlistObj.onplay);
  audioPlayer.addEventListener("ended", playlistObj.trackEnded);
  audioPlayer.addEventListener("error", playlistObj.onerror);
  audioCaption = document.getElementById("audio_caption");
  BODY = document.getElementsByTagName("body")[0];
  BODY.getTotalHeight = function()
  {
    //thanks to 'Borgar'
    // https://stackoverflow.com/a/1147768
    html = document.documentElement;
    return Math.max(BODY.scrollHeight, BODY.offsetHeight,
                    html.clientHeight, html.scrollHeight, html.offsetHeight);
  }
  sessionId = <?php echo "\"" . js_escape($SESSION_ID) . "\";";  ?>
  userAgent = <?php echo "\"" . js_escape($_SERVER['HTTP_USER_AGENT']) . "\""; ?>;
  juffImg.init();
  MultiPane.init();
  MultiPane.registerPopulator("Search", SearchPane);
  MultiPane.registerPopulator("Configuration", ConfigurationPane);
  MultiPane.registerPopulator("Menu", MenuPane);
  MultiPane.registerPopulator("Radio", RadioPane);

  //initialize Playlist with previous session's playlist
  playlistObj.fetchSessionPlaylist();
}
var MultiPane = {
  wrapper: undefined,
  optionsWrapper: undefined,
  populatorArr: new Array(),
  populatorNames: new Array(),
  populatorIcons: new Array(),
  selectedIndex: -1,
  curPopulator: undefined,
  init: function() {
    MultiPane.wrapper = document.querySelector(".multi_pane_wrapper");
    MultiPane.optionsWrapper = document.querySelector(".options_wrapper");
  },
  clear: function() {
    if(MultiPane.curPopulator)
    {
      if(MultiPane.curPopulator && MultiPane.curPopulator.fini)
      {
        MultiPane.curPopulator.fini();
      }
      MultiPane.curPopulator = undefined;
    }
    removeChilds(MultiPane.wrapper);
    MultiPane.selectedIndex = -1;
  },
  registerPopulator(populatorName, populatorObject) {
    var curPop = new populatorObject();
    MultiPane.populatorNames.push(populatorName);
    MultiPane.populatorArr.push(curPop);

    var elementDiv = advancedCreateElement("div", MultiPane.optionsWrapper, "options_element");
    var newImg = document.createElement("img");
    newImg.setAttribute("width", curPop.icon.width);
    newImg.setAttribute("height", curPop.icon.height);
    newImg.setAttribute("src", curPop.icon.src);
    if(curPop.icon.idName) newImg.setAttribute("id", curPop.icon.idName);
    if(curPop.icon.className) newImg.setAttribute("class", curPop.icon.className);
    if(curPop.icon.title) newImg.setAttribute("title", curPop.icon.title);
    newImg = elementDiv.appendChild(newImg);
    MultiPane.populatorIcons.push(elementDiv);

    newImg.addEventListener("click", function(selectedName) { return function() { MultiPane.select(selectedName) }; }(populatorName));

    if(1 == MultiPane.populatorNames.length && -1 == MultiPane.selectedIndex)
    {
      setTimeout(function(selectedName) { return function() { MultiPane.select(selectedName); }; }(populatorName),5);
    }
  },
  select: function(selectedName) {
    var foundIndex = -1;
    for(var i = 0; i < MultiPane.populatorNames.length; ++i)
    {
      if(MultiPane.populatorNames[i] == selectedName)
      {
        foundIndex = i;
        break;
      }
    }
    if(foundIndex != MultiPane.selectedIndex)
    {
      if(-1 < MultiPane.selectedIndex)
      {
        MultiPane.populatorIcons[MultiPane.selectedIndex].setAttribute("class", "options_element");
      }
      if(-1 < foundIndex)
      {
        MultiPane.populatorIcons[foundIndex].setAttribute("class", "options_element options_element_selected");
      }
    }
    MultiPane.clear();
    if(-1 < foundIndex && foundIndex < MultiPane.populatorArr.length)
    {
      advancedCreateElement("div", MultiPane.wrapper, "multi_pane_heading", undefined, selectedName);
      MultiPane.curPopulator = MultiPane.populatorArr[foundIndex];
      MultiPane.selectedIndex = foundIndex;
      MultiPane.curPopulator.init(MultiPane.wrapper);
    }
  }
};
function setSearchVisibility(setVisible)
{
  var rightWrapper = document.querySelector(".right_wrapper");
  for(var i = 0; i < rightWrapper.childNodes.length; ++i)
  {
    if(rightWrapper.childNodes[i].style)
    {
      if(setVisible)
      {
        rightWrapper.childNodes[i].style.display = "block";
      } else {
        rightWrapper.childNodes[i].style.display = "none";
      }
    }
  }
}
function ConfigurationPane()
{
  this.icon = {
    src: "img/gear.png",
    width: 50,
    height: 50,
    idName: "img_gear",
    className: undefined,
    title: "Configuration Pane"
  };
  this.init = function(parentEle) {
    myPane = advancedCreateElement("div", parentEle, "configuration_wrapper");
    var logOutButton = advancedCreateElement("button", myPane, "configuration_button", undefined, "Log Out");
    logOutButton.addEventListener("click", doLogOut);
    var rescanButton = advancedCreateElement("button", myPane, "configuration_button", undefined, "Rescan all files");
    rescanButton.addEventListener("click", function(parentEle) { return function () { if(confirm("Are you sure you want to rescan all files?")) { configurationRescanAllFiles(parentEle); } } }(parentEle));
    var sessionIdEle = advancedCreateElement("div", myPane, "configuration_session_id", undefined, "Session-Id: " + sessionId);
  };
}
function SearchPane()
{
  this.icon = {
    src: "img/looking-glass-big.png",
    width: 48,
    height: 51,
    idName: "img_search",
    className: undefined,
    title: "Search Pane"
  };
  this.inputEle = undefined;
  this.keyboardEle = undefined;
  this.letterEles = new Array();
  this.lettersLowercase = false;
  this.init = function(parentEle) {
    this.inputEle = advancedCreateElement("input", parentEle, "search_input", undefined, undefined);
    this.inputEle.setAttribute("id", "search_input");
    this.inputEle.setAttribute("size", "20");
    // Restrict showing the virtual keyboard
    if(-1 < userAgent.indexOf("(X11; Linux armv7l)"))
    {
      this.inputEle.addEventListener("click", function(inputEle, selfReference) { return function() { selfReference.inputKeyboard(inputEle); } }(this.inputEle, this));
    }
    var listWrapper = advancedCreateElement("div", parentEle, "search_list_wrapper", undefined, undefined);
    listWrapper.setAttribute("id", "search_list_wrapper");
    //set globals
    searchField = this.inputEle;
    searchField.addEventListener("keyup", search_keyup);
    searchListWrapper = listWrapper;
    if(currentTracklist)
    {
      currentTracklist.assumeSearchList();
    } else {
      fetchPopular();
    }
    this.inputEle.focus();
  };
  this.inputKeyboard = function(inputEle)
  {
    if(this.keyboardEle)
    {
      return;
    }
    var keys = [ ['1', '2', '3', '4', '5', '6', '7', '8',
                  '9', '0'], ['Q', 'W', 'E', 'R', 'T', 'Y',
                  'U', 'I', 'O', 'P'], ['A', 'S', 'D', 'F',
                  'G', 'H', 'J', 'K', 'L', ':'], ['Z', 'X',
                  'C', 'V', 'B', 'N', 'M', '.', '-', '_']];
    var keyboardMain = document.createElement("div");
    keyboardMain.setAttribute("class", "keyboard_main_wrapper");
    var keyboardLetters = advancedCreateElement("div", keyboardMain, "keyboard_letter_wrapper");
    for(var i = 0; i < keys.length; ++i)
    {
      for(var j = 0; j < keys[i].length; ++j)
      {
        var curLetter = advancedCreateElement("div", keyboardLetters, "keyboard_key_letter", undefined, keys[i][j]);
        curLetter.addEventListener("click", function(selfReference, keyboardMain, key) { return function() { selfReference.keyClicked(keyboardMain, key); }; }(this, keyboardMain, keys[i][j]));;
        this.letterEles.push(curLetter);
      }
    }
    advancedCreateElement("div", keyboardLetters, "keyboard_key_shift", undefined, "⇫").addEventListener("click", function(selfReference, keyboardMain, key) { return function() { selfReference.keyClicked(keyboardMain, key); }; }(this, keyboardMain, "shift"));
    advancedCreateElement("div", keyboardLetters, "keyboard_key_space", undefined, " ").addEventListener("click", function(selfReference, keyboardMain, key) { return function() { selfReference.keyClicked(keyboardMain, key); }; }(this, keyboardMain, " "));
    advancedCreateElement("div", keyboardMain, "keyboard_key_backspace", undefined, "←").addEventListener("click", function(selfReference, keyboardMain, key) { return function() { selfReference.keyClicked(keyboardMain, key); }; }(this, keyboardMain, "backspace"));
    advancedCreateElement("div", keyboardMain, "keyboard_key_enter", undefined, "↵").addEventListener("click", function(selfReference, keyboardMain, key) { return function() { selfReference.keyClicked(keyboardMain, key); }; }(this, keyboardMain, "\n"));
    this.keyboardEle = BODY.appendChild(keyboardMain);
  };
  this.keyClicked = function(keyboardEle, key)
  {
    if("shift" == key) {
      //TODO: program me somehow
      this.lettersLowercase = !this.lettersLowercase;
      for(var i = 0; i < this.letterEles.length; ++i)
      {
        if(this.lettersLowercase)
        {
          this.letterEles[i].firstChild.nodeValue = this.letterEles[i].firstChild.nodeValue.toLowerCase();
        } else {
          this.letterEles[i].firstChild.nodeValue = this.letterEles[i].firstChild.nodeValue.toUpperCase();
        }
      }
    } else if("backspace" == key)
    {
      var str = this.inputEle.value;
      if(0 < str.length)
      {
        str = str.substring(0, str.length - 1);
        this.inputEle.value = str;
      }
    } else if("\n" == key)
    {
      this.keyboardEle.parentNode.removeChild(this.keyboardEle);
      this.keyboardEle = undefined;
      this.letterEles = new Array();
      this.lettersLowercase = false;
      fetch_tracks("matching_tracks", this.inputEle.value, 0);
    } else
    {
      if(this.lettersLowercase)
      {
        this.inputEle.value += key.toLowerCase();
      } else
      {
        this.inputEle.value += key.toUpperCase();
      }
      if(2 < this.inputEle.value.length)
      {
        fetch_tracks("matching_tracks", this.inputEle.value, 0);
      }
    }
  }
}
function RadioPane()
{
  this.icon = {
    src: "img/radio-inactive.png",
    width: 50,
    height: 50,
    idName: "img_radio",
    className: undefined,
    title: "Radio Pane"
  };
  this.init = function(parentEle) {
    var radioWrapper = advancedCreateElement("div", parentEle, "radio_pane_wrapper");
    advancedCreateElement("p", radioWrapper, undefined, undefined, "The programmer has been too lazy to program this feature yet.");
    advancedCreateElement("div", radioWrapper, "radio_play_button", undefined, "Play Klassik Radio").addEventListener("click", function() {
        audioPlayer.pause();
        audioPlayer.setAttribute("src", "https://klassikr.streamabc.net/klassikradio-simulcast-mp3-mq?sABC=5oq1r14o%230%234ro365069486n29r5oo7n8ps8456q010%237Qvtvgny");
        audioPlayer.play();
      });
  };
}

function MenuPane()
{
  this.icon = {
    src: "img/menu-inactive.png",
    width: 50,
    height: 50,
    idName: "img_menu",
    className: undefined,
    title: "Menu Pane"
  };
  this.init = function(parentEle) {
    var menuWrapper = advancedCreateElement("div", parentEle, "menu_pane_wrapper");
    var ulEle = advancedCreateElement("ul", menuWrapper, "menu_search_list");
    var searchOptions = [
        ["Popular", "popular", "all"],
        ["Popular Playlists", "popular", "playlist"],
        ["Popular Files", "popular", "file"],
        ["Newest", "newest", "all"],
        ["Newest Playlists", "newest", "playlist"],
        ["Newest Files", "newest", "file"]
      ];
    for(var i = 0; i < searchOptions.length; ++i)
    {
      advancedCreateElement("li", ulEle, "menu_search_link", undefined, searchOptions[i][0]).addEventListener(
          "click",
          function(methodName, methodParam) { return function() {
              MultiPane.select("Search");
              fetch_tracks(methodName, methodParam);
            }; }(searchOptions[i][1], searchOptions[i][2])
        );
    }
  };
}

function configurationRescanAllFiles(parentEle)
{
  if(parentEle)
  {
    for(var arrEles = document.querySelectorAll(".configuration_button"), i = 0; i < arrEles.length; ++i)
    {
      arrEles[i].disabled = true;
    }
    advancedCreateElement("br", parentEle);
    var processEle = advancedCreateElement("div", parentEle, "configuration_processing", undefined, ".");
    var req = new XMLHttpRequest();
    req.open("GET", "?ajax&scan_music_dir");
    req.addEventListener("load", function(processEle) {
      return function(evt) {
        //processEle.parentNode.removeChild(processEle);
        var jsonText = evt.target.responseText;
        var jsonObj;
        try {
          jsonObj = JSON.parse(jsonText);
        } catch(exc)
        {
        }
        if(jsonObj && jsonObj.success)
        {
          processEle.firstChild.nodeValue="Successfully rescanned Music Dir";
        } else
        {
          processEle.firstChild.nodeValue="Error occured when trying to rescan";
          console.log(jsonText);
        }
        for(var arrEles = document.querySelectorAll(".configuration_button"), i = 0; i < arrEles.length; ++i)
        {
          arrEles[i].disabled = false;
        }
     }; }(processEle));
    req.send();
    ajax = req;

    setTimeout(updateProcessEle, 200);
    
  }
}
function updateProcessEle()
{
  if(4 > ajax.readyState)
  {
    var processEle = document.querySelector(".configuration_processing");
    if(processEle)
    {
      processEle.firstChild.nodeValue += ".";
      setTimeout(updateProcessEle, 200);
    }
  }
}
function doLogOut()
{
  var splitCookies = document.cookie.split(";");
  for(var i = 0; i < splitCookies.length; ++i)
  {
    var cookieName = splitCookies[i].match(/ *([^=]*)/)[1];
    if(-1 < cookieName.indexOf("access_pwd"))
    {
      document.cookie = cookieName + "=;expires=" + (new Date(0)).toGMTString();
    }
  }
  location.reload();
}
var juffImg = {
  imgArr: [
    {
      src: "img/logo.png",
      width: 178,
      height: 200
    },
    {
      src: "img/country.png",
      width: 140,
      height:165 
    },
    {
      src: "img/rock.png",
      width: 151,
      height: 200
    },
    {
      src: "img/hiphop.png",
      width: 168,
      height: 200
    },
   ],
  ele: undefined,
  init: function()
  {
    juffImg.ele = document.getElementById("juff_img");
    juffImg.setImg(0);
  },
  setImg: function(imgName)
  {
    var match = juffImg.imgArr[0];
    if("string" == (typeof imgName))
    {
      for(var i = 0; i < juffImg.imgArr.length; ++i)
      {
        if(juffImg.imgArr[i].match(imgName))
        {
          match = juffImg.imgArr[i];
          break;
        }
      }
    } else if("number" == (typeof imgName))
    {
      if(-1 < imgName && juffImg.imgArr.length > imgName)
      {
        match = juffImg.imgArr[imgName];
      }
    }
    juffImg.ele.setAttribute("src",    match.src);
    juffImg.ele.setAttribute("width",  match.width);
    juffImg.ele.setAttribute("height", match.height);
  },
  getImgCount: function()
  {
    return juffImg.imgArr.length;
  }
};
function PlaylistClass()
{
  this.boundHtml;
  this.titleHtml;
  this.listHtml;
  this.optionsHtml;
  this.htmlTrackCount = 0;
  this.tracks = new Array();
  this.offset = 0;
  this.previousId = -1;
  this.loop = "none";
  this.myName = "";
  this.playRandom = false;
  this.randomArr = new Array();
  this.randomOffset = 0;
  this.playlistName = undefined;
  this.lastChangeTime = 0;
  this.savingTimeout = false;
  this.assumePlaylist = function()
  {
    if(playlistEle)
    {
      playlistEle.parentNode.removeChild(playlistEle);
    }
    var myEle = document.createElement("div");
    myEle.setAttribute("class", "playlist");
    playlistWrapper.appendChild(myEle);
    this.htmlTrackCount = 0;
    this.boundHtml = myEle;
    playlistEle = myEle;
    this.titleHtml = document.createElement("div");
    this.titleHtml.setAttribute("class", "playlist_title");
    this.titleHtml = this.boundHtml.appendChild(this.titleHtml);
    this.listHtml = document.createElement("div");
    this.listHtml.setAttribute("class", "playlist_list");
    this.listHtml = this.boundHtml.appendChild(this.listHtml);
    this.populateOptions();
  }
  this.populateOptions = function()
  {
    this.optionsHtml = document.createElement("div");
    this.optionsHtml.setAttribute("class", "playlist_option_wrapper");
    //add icons
    var optionEle,imgEle;
    var optionsImgs = new Array("img/loopone.png", "img/loopall.png", "img/random.png", "img/save.png", "img/delete.png");
    var optionsTitles = new Array("Loop currently played Song", "Loop whole list", "Play playlist in a random order", "Save as permanently stored playlist", "Discard all entries");
    for(var i = 0; i < optionsImgs.length; ++i)
    {
      optionEle = document.createElement("div");
      imgEle = document.createElement("img");
      optionEle.setAttribute("class", "playlist_option_div");
      imgEle.setAttribute("class", "playlist_option_img");
      imgEle.setAttribute("src", optionsImgs[i]);
      optionEle.setAttribute("title", optionsTitles[i]);
      optionEle.appendChild(imgEle);
      this.optionsHtml.appendChild(optionEle);
    }
    this.optionsHtml = this.boundHtml.appendChild(this.optionsHtml);
    this.optionsHtml.childNodes[0].addEventListener("click", function() { playlistObj.loopClicked("one"); } );
    this.optionsHtml.childNodes[1].addEventListener("click", function() { playlistObj.loopClicked("all"); } );
    this.optionsHtml.childNodes[2].addEventListener("click", playlistObj.randomClicked);
    this.optionsHtml.childNodes[3].addEventListener("click", playlistObj.save);
    this.optionsHtml.childNodes[4].addEventListener("click", function () { if(confirm("Do you really want to discard the whole list?")) { playlistObj.clearPlaylist(); } });
  }
  this.fetchSessionPlaylist = function()
  {
    var req = new XMLHttpRequest();
    req.open("GET", "?ajax&request_session_playlist=" + encodeURIComponent(sessionId));
    req.addEventListener("load", function(param) {
      var responseJSON = JSON.parse(param.target.responseText);
      if(responseJSON.success && responseJSON.matches)
      {
        for(var i = 0; i < responseJSON.matches.length; ++i)
        {
          playlistObj.enqueueLast(responseJSON.matches[i].id, responseJSON.matches[i].type, responseJSON.matches[i].name);
        }
      }
    });
    req.send();
  }
  this.doCommitIfNoRecentChange = function(paramLastChangeTime)
  {
    if(playlistObj.lastChangeTime == paramLastChangeTime)
    {
      var idString = "";
      for(var i = 0; i < playlistObj.tracks.length; ++i)
      {
        if(0 < i) idString += ",";
        idString += "" + playlistObj.tracks[i].id;
      }
      var req = new XMLHttpRequest();
      req.open("POST", "?ajax&put_session_playlist=" + sessionId);
      req.addEventListener("load", function(param) {
        try {
          if(JSON.parse(param.target.responseText).success)
          {
            console.log("Saved session playlist");
          } else
          {
            console.log("Could not save session playlist");
          }
        } catch(exc)
        {
          console.log("Error when saving session playlist");
        }
      });
      req.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
      req.send("tracks=" + encodeURIComponent(idString));
    }
  }
  this.changedPlaylist = function()
  {
    var curTime = (new Date()).getTime();
    this.lastChangeTime = (new Date()).getTime();
    if(false != this.savingTimeout)
    {
      clearTimeout(this.savingTimeout);
      this.savingTimeout = false;
    }
    if(false == this.savingTimeout)
    {
      this.savingTimeout = setTimeout(function(paramLastChangeTime) { return function() { playlistObj.doCommitIfNoRecentChange(paramLastChangeTime); }; }(curTime), 2000);
    }
  }
  this.enqueueLast = function(trackId, trackType, trackName)
  {
    if("file" == trackType)
    {
      var newTrack = new TrackClass(trackId, trackType, trackName);
      this.tracks.push(newTrack);
      this.addTrackHtml(newTrack, this.tracks.length - 1);
      if(this.playRandom)
      {
        this.randomArr.splice(this.randomOffset + 1 + Math.floor(Math.random() * (this.randomArr.length - 1 - this.randomOffset)), 0, this.tracks.length - 1);
      }
    } else if("playlist" == trackType)
    {
      this.fetchPlaylist(trackId, trackName, "last");
    }
    this.changedPlaylist();
  }
  this.enqueueNext = function(trackId, trackType, trackName)
  {
    playlistObj.enqueueAt(trackId, trackType, trackName, -1);
  }
  this.enqueueAt = function(trackId, trackType, trackName, posAt)
  {
    if("file" == trackType)
    {
      var newTrack = new TrackClass(trackId, trackType, trackName);
      var newPos = posAt;
      if(-1 == newPos )
      {
        newPos = this.offset + 1;
      }
      if(newPos <= this.offset)
      {
        this.offset += 1;
      }
      if(this.tracks.length > newPos)
      {
        this.tracks.splice(newPos, 0, newTrack);
      } else
      {
        this.tracks.push(newTrack);
      }
      this.addTrackHtml(newTrack, newPos);
      if(this.playRandom)
      {
        for(var i = 0; i < this.randomArr.length; ++i)
        {
          if(this.randomArr[i] >= newPos)
          {
            this.randomArr[i] = this.randomArr[i] + 1;
          }
        }
        if(-1 == posAt)
        {
          this.randomArr.splice(this.randomOffset + 1, 0, newPos);
        } else {
          this.randomArr.splice(this.randomOffset + 1 + Math.floor(Math.random() * (this.randomArr.length - this.randomOffset - 2)), 0, newPos);
        }
      }
    } else if("playlist" == trackType)
    {
      this.fetchPlaylist(trackId, trackName, "next");
    }
    this.changedPlaylist();
  }
  this.addTrackHtml = function(trackObj, position)
  {
    var trackLink = document.createElement("a");
    trackLink.setAttribute("href", "javascript:playlistObj.playOffset(" + position + ")");
    trackLink.setAttribute("class", "playlist_link");
    var trackEle = document.createElement("div");
    if(position == this.offset)
    {
      trackEle.setAttribute("class", "playlist_element playlist_selected_element");
    } else
    {
      trackEle.setAttribute("class", "playlist_element");
    }
    trackEle.appendChild(document.createTextNode(trackObj.beautifiedName));
    trackEle.setAttribute("title", "Jump to: " + trackObj.beautifiedName);
    trackEle = trackLink.appendChild(trackEle);
    if(position == (this.htmlTrackCount + 1))
    {
      trackLink = this.listHtml.appendChild(trackLink);
    } else
    {
      this.listHtml.insertBefore(trackLink, this.listHtml.childNodes[position]);
      for(var i = position + 1; i < this.listHtml.childNodes.length; ++i)
      {
        this.updateListElement(i);
      }
    }
    this.htmlTrackCount++;
    trackLink.setAttribute("playlist_offset", position);
    trackLink.addEventListener("contextmenu", function(position) {
      var contextHandler = function(evt) { 
        evt.preventDefault();
        var position = parseInt(evt.target.parentNode.getAttribute("playlist_offset"));
        playlistObj.contextMenuFor(evt.pageX, evt.pageY, evt.target, position);
      };
    return contextHandler;
    }(position), false);
  };
  this.contextMenuFor = function(posX, posY, ele, position)
  {
    new ContextMenuClass(posX, posY, ele, [["Jump To", function(position) {
      return function() { playlistObj.playOffset(position);}; }(position)],
      ["Enqueue Next", function(position) {
      return function() { var swapTrack = playlistObj.tracks[position]; playlistObj.removeTrack(position); playlistObj.enqueueNext(swapTrack.id, swapTrack.type, swapTrack.name); }; }(position)],
      ["Duplicate", function(position) {
      return function() { var duplicateTrack = playlistObj.tracks[position]; playlistObj.enqueueAt(duplicateTrack.id, duplicateTrack.type, duplicateTrack.name, position + 1); }; }(position) ],
      ["Remove", function(position) {
      return function() { playlistObj.removeTrack(position); };}(position)]
    ]);
  };
  this.updateListElement = function(position)
  {
    this.listHtml.childNodes[position].setAttribute("playlist_offset", position);
    this.listHtml.childNodes[position].setAttribute("href", "javascript:playlistObj.playOffset(" + position + ")");
  };
  this.removeTrack = function(position)
  {
    this.tracks.splice(position, 1);
    this.listHtml.removeChild(this.listHtml.childNodes[position]);
    if(position == playlistObj.offset)
    {
      removeChilds(audioCaption);
      audioPlayer.pause();
      playlistObj.previousId = -1;
      audioPlayer.preload = "none";
      audioPlayer.setAttribute("src", "");
    }
    if(position < playlistObj.offset)
    {
      playlistObj.offset--;
    }
    if(position < (this.tracks.length - 1))
    {
      for(var i = position; i < this.tracks.length; ++i)
      {
        this.updateListElement(i);
      }
    }
    if(playlistObj.randomArr && 0 < playlistObj.randomArr.length)
    {
      for(var i = 0; i < playlistObj.randomArr.length; ++i)
      {
        if(playlistObj.randomArr[i] > position)
        {
          playlistObj.randomArr[i]--;
        } else if(playlistObj.randomArr[i] == position)
        {
          playlistObj.randomArr.splice(i, 1);
          if(playlistObj.randomOffset > i)
          {
            playlistObj.randomOffset--;
          }
          i--;
        }
      }
    }
    this.changedPlaylist();
  };
  this.scrollTo = function(offset)
  {
    if(this.listHtml && (this.listHtml.scrollTo || this.listHtml.scroll))
    {
      var cumulativeHeight = 0;
      for(var i = 0; i < offset; ++i)
      {
        //need to get the height of the "div"-element (i.e. firstChild),
        //because chrome refuses to report a height
        //for the surrounding "a"-element
        cumulativeHeight += this.listHtml.childNodes[i].firstChild.offsetHeight;
      }
      if(this.listHtml.scrollTo)
      {
        this.listHtml.scrollTo({
          left: 0,
          top: cumulativeHeight,
          behavior: "smooth"});
      } else
      {
        this.listHtml.scroll(0, cumulativeHeight);
      }
    }
  }
  this.length = function()
  {
    return this.tracks.length;
  }
  this.playOffset = function(newOffset)
  {
    if(this.playRandom)
    {
      this.playRandom = false;
      this.setHtmlOption(2, false);
    }
    if(-1 < newOffset && newOffset < playlistObj.tracks.length)
    {
      this.listHtml.childNodes[this.offset].firstChild.setAttribute("class", "playlist_element");
      playlistObj.offset = newOffset;
      this.listHtml.childNodes[this.offset].firstChild.setAttribute("class", "playlist_element playlist_selected_element");
    }
    playlistObj.play();
  }
  this.togglePlayPause = function()
  {
    if(audioPlayer.paused)
    {
      playlistObj.play(true);
    } else
    {
      playlistObj.pause();
    }
  }
  this.pause = function()
  {
    audioPlayer.pause();
  }
  this.play = function(doContinuePlaying)
  {
    try {
      if(this.offset >= this.tracks.length)
      {
        this.offset = 0;
      }
      if(this.offset < this.tracks.length)
      {
        if(this.previousId != this.tracks[this.offset].id)
        {
          var requestUrl = "?ajax&request_track=" + this.tracks[this.offset].id;
          audioPlayer.pause();
          audioPlayer.setAttribute("src", requestUrl);
          audioPlayer.preload = "auto";
          juffImg.setImg(Math.floor(1 + Math.random() * (juffImg.getImgCount() - 1)));
        } else
        {
          if(!doContinuePlaying)
          {
            audioPlayer.currentTime = 0;
          }
        }
        audioPlayer.play();
        removeChilds(audioCaption);
        audioCaption.appendChild(document.createTextNode(this.tracks[this.offset].beautifiedName));
        this.scrollTo(this.offset);
        this.previousId = this.tracks[this.offset].id;
      }
    } catch(exc)
    {
      alert(exc);
    }
  }
  this.advance = function(direction)
  {
    if(0 != direction)
    {
      this.listHtml.childNodes[this.offset].firstChild.setAttribute("class", "playlist_element");
      if(this.playRandom)
      {
        this.randomOffset = (this.randomOffset + direction) % this.randomArr.length;
        this.offset = this.randomArr[this.randomOffset];
      } else
      {
        this.offset = (this.offset + direction) % this.tracks.length;
      }
      this.listHtml.childNodes[this.offset].firstChild.setAttribute("class", "playlist_element playlist_selected_element");
    }
  }
  this.playNext = function()
  {
    playlistObj.advance(1);
    playlistObj.play();
  }
  this.onerror = function(evt)
  {
    if(audioPlayer.networkState == HTMLMediaElement.NETWORK_NO_SOURCE)
    {
      playlistObj.trackEnded(evt);
    } else
    {
      console.log("Miscellenaeous error occured with audio Player.");
      console.log(evt);
    }
  }
  this.onwheel = function(evt)
  {
    evt.preventDefault();
    audioPlayer.currentTime = audioPlayer.currentTime + evt.deltaY;
  }
  this.onplay = function(evt)
  {
    playlistObj.play(true);
  }
  this.trackEnded = function(evt)
  {
    if("none" == playlistObj.loop)
    {
      if(playlistObj.playRandom)
      {
        if((playlistObj.randomOffset + 1) < playlistObj.randomArr.length)
        {
          playlistObj.advance(1);
          playlistObj.play();
        }
      } else
      {
        if((playlistObj.offset + 1) < playlistObj.tracks.length)
        {
          playlistObj.advance(1);
          playlistObj.play();
        }
      }
    } else if("all" == playlistObj.loop)
    {
      playlistObj.advance(1);
      playlistObj.play();
    } else if("one" == playlistObj.loop)
    {
      //don't advance offset
      playlistObj.play();
    }
  }
  this.setHtmlOption = function(optionNumber, optionEnabled)
  {
    if(optionEnabled)
    {
      playlistObj.optionsHtml.childNodes[optionNumber].setAttribute("class","playlist_option_div playlist_option_div_selected");
    } else
    {
      playlistObj.optionsHtml.childNodes[optionNumber].setAttribute("class","playlist_option_div");
    }
  }
  this.randomClicked = function()
  {
    playlistObj.playRandom = ! playlistObj.playRandom;
    playlistObj.setHtmlOption(2, playlistObj.playRandom);

    if(playlistObj.playRandom)
    {
      var randCopy = new Array();
      var randSelect = new Array();
      for(var i = 0; i < playlistObj.tracks.length; ++i)
      {
        if(i == playlistObj.offset)
        {
          randSelect.push(i);
        } else {
          randCopy.push(i);
        }
      }
      while(randCopy.length > 1)
      {
        var selectedIndex = Math.floor(Math.random() * randCopy.length);
        var popped = randCopy.splice(selectedIndex, 1);
        randSelect.push(popped[0]);
      }
      if(0 < randCopy.length) randSelect.push(randCopy[0]);
      playlistObj.randomArr = randSelect;
      playlistObj.randomOffset = 0;
    }
  }
  this.loopClicked = function(loopStr)
  {
    if(loopStr == playlistObj.loop)
    {
      playlistObj.loop = "none";
    } else {
      playlistObj.loop = loopStr;
    }

    playlistObj.setHtmlOption(0, "one" == playlistObj.loop);
    playlistObj.setHtmlOption(1, "all" == playlistObj.loop);
  }
  this.clearPlaylist = function()
  {
    playlistObj.offset = 0;
    playlistObj.tracks = new Array();
    playlistObj.htmlTrackCount = 0;
    removeChilds(audioCaption);
    removeChilds(playlistObj.listHtml);
    audioPlayer.pause();
    playlistObj.previousId = -1;
    audioPlayer.preload = "none";
    /* this causes firefox to complain
     * "Invalid URI. Load of media resource  failed."
     * however, I don't know how to tell firefox to forget
     * what was stored in an audio element. Although I have
     * tried using the recommended DOM-way by adding <source>
     * elements, that does not work either.
     */
    audioPlayer.setAttribute("src", "");

    playlistObj.setPlaylistName(undefined);
    playlistObj.changedPlaylist();
  }
  this.fetchPlaylist = function(playlistId, playlistName, enqueueWhere)
  {
    var req = new XMLHttpRequest();
    req.open("GET", "?ajax&request_playlist=" + encodeURIComponent(playlistId));
    req.playlistName = playlistName;
    req.addEventListener("load", function(param) {
      var responseJSON = JSON.parse(param.target.responseText);
      if(responseJSON.success && responseJSON.matches)
      {
        if(0 == playlistObj.tracks.length)
        {
          playlistObj.setPlaylistName(param.target.playlistName);
        }
        for(var i = 0; i < responseJSON.matches.length; ++i)
        {
          if(enqueueWhere == "last") playlistObj.enqueueLast(responseJSON.matches[i].id, responseJSON.matches[i].type, responseJSON.matches[i].name);
          else if(enqueueWhere == "next") playlistObj.enqueueNext(responseJSON.matches[i].id, responseJSON.matches[i].type, responseJSON.matches[i].name);
        }
      }
    });
    req.send();
  }
  this.save = function()
  {
    var returnVal;
    if(playlistObj.playlistName && 0 < playlistObj.playlistName.length)
    {
      returnVal = prompt("Please enter name of Playlist:", playlistObj.playlistName);
    } else
    {
      returnVal = prompt("Please enter name of Playlist:");
    }
    if(returnVal)
    {
      playlistObj.myName = returnVal;
      var idString = "";
      for(var i = 0; i < playlistObj.tracks.length; ++i)
      {
        if(0 < i) idString += ",";
        idString += "" + playlistObj.tracks[i].id;
      }
      var req = new XMLHttpRequest();
      req.open("POST", "?ajax&put_playlist");
      req.addEventListener("load", function(param) {
        try {
          if(JSON.parse(param.target.responseText).success)
          {
            console.log("Saved playlist");
          } else
          {
            console.log("Could not save playlist");
          }
        } catch(exc)
        {
          console.log("Error when saving playlist");
        }
      });
      req.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
      req.send("playlist_name=" + encodeURIComponent(playlistObj.myName) + "&playlist_tracks=" + encodeURIComponent(idString));
      playlistObj.setPlaylistName(returnVal);
    }
  }
  this.setPlaylistName = function(playlistName)
  {
    playlistObj.playlistName = playlistName;
    removeChilds(playlistObj.titleHtml);
    if(playlistName)
    {
      playlistObj.titleHtml.appendChild(document.createTextNode("Playlist: " + playlistName));
    }
  }
}
/* TODO: extend this by adding field 'countPlayed' */
function TrackClass(trackId, trackType, trackName, trackCountPlayed, trackTags)
{
  this.id = trackId;
  this.type = trackType;
  this.countPlayed = trackCountPlayed;
  this.tags = ("" + trackTags).split(",");
  this.name = trackName;
  this.beautifiedName = this.name;
  if("file" == this.type)
  {
    this.beautifiedName = basename(this.beautifiedName);
    this.beautifiedName = beautifySongName(this.beautifiedName);
  } else if("playlist" == this.type)
  {
    this.beautifiedName = "PL: " + this.name;
  }
}

function ContextMenuClass(posX, posY, parentNode, optionsArr)
{
  this.menuEle;
  this.itemsArr;
  this.overlayArr;
  if(contextMenu)
  {
    if(contextMenu.selfDestruct) contextMenu.selfDestruct();
  }
  contextMenu = this;
  this.selfDestruct = function()
  {
    if(contextMenu.overlayArr)
    {
      for(var i = 0; i < contextMenu.overlayArr.length; i++)
      {
        contextMenu.overlayArr[i].parentNode.removeChild(contextMenu.overlayArr[i]);
      }
    }
    contextMenu.menuEle.parentNode.removeChild(contextMenu.menuEle);
    contextMenu = undefined;
  }
  if(!parentNode)
  {
    this.overlayArr = new Array();
    this.overlayArr.push(document.createElement("div"));
    this.overlayArr[0].setAttribute("class", "overlay_veil");
    this.overlayArr[0] = BODY.appendChild(this.overlayArr[0]);
    this.overlayArr[0].addEventListener("click", function() {if(contextMenu && contextMenu.selfDestruct) contextMenu.selfDestruct();});
  } else
  {
    /* TODO: if parentNode is given, paint a veil around the parentNode
     */
    this.overlayArr = new Array();
    this.overlayArr.push(document.createElement("div"));
    this.overlayArr[0].setAttribute("class", "overlay_veil");
    this.overlayArr[0].style.position = "absolute";
    this.overlayArr[0].style.left=0;
    this.overlayArr[0].style.top =0;
    this.overlayArr[0] = BODY.appendChild(this.overlayArr[0]);
    this.overlayArr[0].addEventListener("click", function() {if(contextMenu && contextMenu.selfDestruct) contextMenu.selfDestruct();});
  }
  this.menuEle = document.createElement("div");
  this.menuEle.setAttribute("class", "contextmenu_wrapper");
  this.menuEle.style.position = "absolute";
  if((posY + (optionsArr.length * 35) + 5) < BODY.getTotalHeight())
  {
    this.menuEle.style.top = posY;
  } else
  {
    this.menuEle.style.top = posY - (optionsArr.length * 35 + 5);
  }
  if(posX + 150 < BODY.offsetWidth)
  {
    this.menuEle.style.left = posX;
  } else
  {
    this.menuEle.style.left = posX - 150;
  }
  var closeEle = document.createElement("div");
  closeEle.setAttribute("class", "contextmenu_close");
  closeEle.appendChild(document.createTextNode("X"));
  closeEle.addEventListener("click", function() {if(contextMenu && contextMenu.selfDestruct) contextMenu.selfDestruct();});
  this.menuEle.appendChild(closeEle);
  for(var i = 0; i < optionsArr.length; ++i)
  {
    var curEle = document.createElement("div");
    curEle.appendChild(document.createTextNode(optionsArr[i][0]));
    if(optionsArr[i][1])
    {
      curEle.addEventListener("click", function(callback) { return function() {callback(); contextMenu.selfDestruct();} }(optionsArr[i][1]), false);
    }
    curEle.setAttribute("class", "contextmenu_item");
    this.menuEle.appendChild(curEle);
  }
  this.menuEle.style.height = "calc(" + Math.max(2, optionsArr.length * 2) + "em - 5px)";
  BODY.appendChild(this.menuEle);
}

function onTagClicked(tagName)
{
  searchField.value = tagName;
  fetch_tracks("matching_tracks", tagName, 0);
}
function search_keyup(eventObj)
{
  var searchSubject = searchField.value;
  if(2 < searchSubject.length)
  {
    fetch_tracks("matching_tracks", searchSubject, 0);
  } else if(eventObj && "Enter" == eventObj.code)
  {
    removeChilds(searchListWrapper);
  }
}
function fetchPopular() { fetch_tracks("popular", "brief_all", 0); }
function fetch_tracks(methodName, methodParam, offset)
{
  var ajax = new XMLHttpRequest();
  ajax.open("GET", "?ajax&" + encodeURIComponent(methodName) + "=" + encodeURIComponent(methodParam) + "&matching_offset=" + encodeURIComponent(offset));
  ajax.addEventListener("load", function(param) {
    console.log("Request for " + methodName + "=" + methodParam + " took " + (((new Date()).getTime() - param.target.requestSendedTime)/1000) + " seconds");
    process_matching_tracks(methodName, methodParam, param.target.responseText, param.target.requestSendedTime);
   });
  ajax.requestSendedTime = (new Date()).getTime();
  ajax.send();
}
function Tracklist(methodName, methodParam, tracklistJSON, requestSendedTime)
{
  this.tracks = new Array();
  this.pageLimit = 100;
  this.pageOffset = 0;
  this.matchCount = 0;
  this.requestSendedTime = requestSendedTime;
  this.getMethod = methodName;
  this.getParam = methodParam;
  if(tracklistJSON.success)
  {
    this.matchCount = tracklistJSON.countMatches;
    this.pageOffset = tracklistJSON.offsetMatches;
    this.pageLimit  = tracklistJSON.pageLimit;
    for(var i = 0; i < tracklistJSON.matches.length; ++i)
    {
      this.tracks.push(new TrackClass(tracklistJSON.matches[i].id, tracklistJSON.matches[i].type, tracklistJSON.matches[i].name, tracklistJSON.matches[i].countPlayed, tracklistJSON.matches[i].tags));
    }
  }
  /* TODO: split up the code of this function
   *  into separate specialized functions
   *  because it's too long
   */
  this.assumeSearchList = function()
  {
    removeChilds(searchListWrapper);
    if(this.matchCount > this.pageLimit)
    {
      var curPage = Math.floor(this.pageOffset / this.pageLimit);
      var maxPages = Math.ceil(this.matchCount / this.pageLimit);
      var showPages = new Array();
      for(var i = curPage - 2; i < (curPage + 3); ++i)
      {
        if(i >= 0 && i < maxPages)
        {
          showPages.push(i);
        }
      }
      if(1 < showPages.length)
      {
        if(0 < showPages[0])
        {
          showPages.unshift(0);
        }
        if(maxPages > (showPages[showPages.length - 1] + 1))
        {
          showPages.push(maxPages - 1);
        }
        var pageNumEle = document.createElement("div");
        pageNumEle.setAttribute("class", "paging_wrapper");
        for(var i = 0; i < showPages.length; ++i)
        {
          var fillerEle = document.createElement("span");
          fillerEle.setAttribute("class", "paging_filler");
          if(0 < i && 1 < Math.abs(showPages[i] - showPages[i - 1]))
          {
            var fillerLink = advancedCreateElement("a", fillerEle, undefined, undefined, "...");
            fillerLink.addEventListener("click", function(max, cur, pageLimit, methodName, methodParam) { return function(evt) {
              var desiredPage = parseInt(prompt("Which page is to be loaded? (maximum " + max + ")", cur + 1));
              if(0 < desiredPage && desiredPage <= max)
              {
                fetch_tracks(methodName, methodParam, (desiredPage - 1) * pageLimit);
              }
              };}(maxPages, curPage, this.pageLimit, this.getMethod, this.getParam));
            fillerEle.appendChild(fillerLink);
          } else
          {
            fillerEle.appendChild(document.createTextNode("  "));
          }
          pageNumEle.appendChild(fillerEle);
          var curPageNumEle = document.createElement("a");
          var className = "paging_button";
          if(0 == i) className += " paging_button_first";
          if((showPages.length - 1) == i) className += " paging_button_last";
          if(showPages[i] == curPage) className += " paging_button_current";
          curPageNumEle.setAttribute("class", className);
          if(showPages[i] != curPage)
          {
            curPageNumEle.setAttribute("href", "javascript:fetch_tracks('" + this.getMethod + "', '" + this.getParam + "'," + showPages[i] * this.pageLimit + ")");
          }
          curPageNumEle.appendChild(document.createTextNode("" + (showPages[i] + 1)));
          pageNumEle.appendChild(curPageNumEle);
        }
        var trailingEle = document.createElement("span");
        trailingEle.setAttribute("class", "paging_trailing");
        pageNumEle.appendChild(trailingEle);
        searchListWrapper.appendChild(pageNumEle);
      }
    }
    for(var i = 0; i < this.tracks.length; ++i)
    {
      var linkEle = document.createElement("a");
      linkEle.setAttribute("href", "javascript:searchTrackLeftclicked(" + this.tracks[i].id + ", \"" + this.tracks[i].type + "\", \"" + this.tracks[i].name + "\")");
      linkEle.setAttribute("title", "Enqueue: " + this.tracks[i].beautifiedName);
      linkEle.addEventListener("contextmenu", function (listEle, trackId, trackType, trackName) { return function (evt) { evt.preventDefault(); searchTrackRightclicked(evt, listEle, trackId, trackType, trackName); }; }(linkEle, this.tracks[i].id, this.tracks[i].type, this.tracks[i].name));
      linkEle.setAttribute("class", "search_list_link");
      var divEle = document.createElement("div");
      divEle.setAttribute("class", "search_list_element");
      divEle.appendChild(document.createTextNode(this.tracks[i].beautifiedName));
      for(var j = 0; j < this.tracks[i].tags.length; ++j)
      {
        var tagEle = document.createElement("div");
        var tagName = this.tracks[i].tags[j];
        tagEle.setAttribute("class", "search_list_tag");
        tagEle.setAttribute("title", "search for \"" + tagName + "\" by right-clicking");
        tagEle.appendChild(document.createTextNode(tagName));
        tagEle.addEventListener("contextmenu", function(tagName) { return function(evt) { evt.stopPropagation(); evt.preventDefault(); onTagClicked(tagName); }; }(tagName));
        divEle.appendChild(tagEle);
      }
      var countPlayedEle = document.createElement("div");
      countPlayedEle.setAttribute("class", "search_list_count_played");
      var playedText = "";
      if(1 > this.tracks[i].countPlayed)
      {
          playedText = "not yet played";
      } else if (1 == this.tracks[i].countPlayed)
      {
          playedText = "1 time played";
      } else
      {
          playedText = this.tracks[i].countPlayed + " times played";
      }
      countPlayedEle.appendChild(document.createTextNode(playedText));
      divEle.appendChild(countPlayedEle);

      linkEle.appendChild(divEle);
      searchListWrapper.appendChild(linkEle);
    }
  }
}
function process_matching_tracks(methodName, methodParam, responseText, requestSendedTime)
{
  if(currentTracklist && currentTracklist.requestSendedTime > requestSendedTime)
  {
    return;
  }
  removeChilds(searchListWrapper);
  var responseJSON;
  try
  {
    responseJSON = JSON.parse(responseText);
  } catch(exc)
  {
    console.log(exc);
    searchListWrapper.appendChild(document.createTextNode("JS-Error: Could not parse server response as JSON."));
    console.log(responseText);
    return;
  }
  if(responseJSON)
  {
    currentTracklist = new Tracklist(methodName, methodParam, responseJSON, requestSendedTime);
    currentTracklist.assumeSearchList();
  }
}
function searchTrackRightclicked(evt, listEle, trackId, trackType, trackName)
{
  if("file" == trackType)
  {
    new ContextMenuClass(evt.pageX, evt.pageY, evt.target, [
        ["Enqueue", undefined],
        ["⤷ as last", function(trackId,trackName){ return function(evt) {
          playlistObj.enqueueLast(trackId, trackType, trackName);
          if(1 == playlistObj.length())
          {
            playlistObj.play();
          }
        }; }(trackId, trackName)],
        [ "⤷ as next", function(trackId,trackName){ return function(evt) {
          playlistObj.enqueueNext(trackId, trackType, trackName);
          if(1 == playlistObj.length())
          {
            playlistObj.play();
          }
        }; }(trackId, trackName)],
        [ "⤷ as first", function(trackId,trackName){ return function(evt) {
          playlistObj.enqueueAt(trackId, trackType, trackName, 0);
          if(1 == playlistObj.length(), 0)
          {
            playlistObj.play();
          }
        }; }(trackId, trackName)],
          ["Play", function(trackId,trackName){ return function(evt) {
          playlistObj.clearPlaylist();
          playlistObj.enqueueLast(trackId, trackType, trackName);
          playlistObj.play();
        }; }(trackId, trackName)]
      ]);
  } else if("playlist" == trackType)
  {
    new ContextMenuClass(evt.pageX, evt.pageY, evt.target, [
          ["Play", function(trackId,trackName){ return function(evt) {
          playlistObj.clearPlaylist();
          playlistObj.enqueueLast(trackId, trackType, trackName);
          playlistObj.play();
        }; }(trackId, trackName)],
        ["Enqueue", function(trackId,trackName){ return function(evt) {
          playlistObj.enqueueLast(trackId, trackType, trackName);
          if(1 == playlistObj.length())
          {
            playlistObj.play();
          }
        }; }(trackId, trackName)],
        [ "Enqueue Next", function(trackId,trackName){ return function(evt) {
          playlistObj.enqueueNext(trackId, trackType, trackName);
          if(1 == playlistObj.length())
          {
            playlistObj.play();
          }
        }; }(trackId, trackName)]
      ]);
  }
}
function searchTrackLeftclicked(trackId, trackType, trackName)
{
  if("file" == trackType)
  {
    playlistObj.enqueueLast(trackId, trackType, trackName);
    if(1 == playlistObj.length())
    {
      playlistObj.play();
    }
  } else if("playlist" == trackType)
  {
    playlistObj.clearPlaylist();
    playlistObj.enqueueLast(trackId, trackType, trackName);
    playlistObj.play();
  }
}
function advancedCreateElement(tagName, parentNode, className, styles, text)
{
  if(!tagName)
  {
    return;
  }
  var ele = document.createElement(tagName);
  if(className)
  {
    ele.setAttribute("class", className);
  }
  if(styles)
  {
    ele.setAttribute("style", styles);
  }
  if(text)
  {
    ele.appendChild(document.createTextNode(text));
  }
  if(parentNode)
  {
    ele = parentNode.appendChild(ele);
  } else
  {
    ele = BODY.appendChild(ele);
  }
  return ele;
}
function removeChilds(parentNode)
{
  for(var i = parentNode.childNodes.length - 1; i >= 0; i--)
  {
    parentNode.removeChild(parentNode.childNodes[i]);
  }
}
function basename(filepath)
{
  var matchEnd = filepath.match(/[^/]+$/);
  if(matchEnd && matchEnd.length)
  {
    return matchEnd[0];
  } else {
    return filepath;
  }
}
function beautifySongName(filename)
{
  var beautified = filename.replace(/\.[a-zA-Z0-9]{1,6}$/, "");
  beautified = beautified.replace(/_id[-_a-zA-Z0-9]{4,15}$/, "");
  beautified = beautified.replace(/_/g, " ");
  beautified = beautified.replace(/^ +/g, "");
  beautified = beautified.replace(/ +$/g, "");
  beautified = beautified.replace(/ HD$/i, "");
  beautified = beautified.replace(/ []$/, "");
  beautified = beautified.replace(/Official Music Video$/i, "");
  beautified = beautified.replace(/Music Video$/i, "");
  beautified = beautified.replace(/Official Video$/i, "");
  beautified = beautified.replace(/Official Video HQ$/i, "");
  beautified = beautified.replace(/Original HQ$/i, "");
  beautified = beautified.replace(/Official Video VOD$/i, "");
  beautified = beautified.replace(/Videoclip$/i, "");
  beautified = beautified.replace(/\(official\) /i, "");
  var withoutLeadingNumbers = beautified.replace(/^[0-9]{1,4} ?(- )?/, "");
  if(1 < withoutLeadingNumbers.length)  beautified = withoutLeadingNumbers;
  beautified = beautified.replace(/^[-~.] */, "");
  return beautified;
}
function handle_global_keydown(evt)
{
  //check if this event is targeted at some input element
  if(evt && evt.path && 0 < evt.path.length && "INPUT" == evt.path[0].tagName)
  {
    return;
  } else
  {
    if(evt.keyCode)
    {
      if(32 == evt.keyCode)
      {
        playlistObj.togglePlayPause();
        evt.preventDefault()
      }
    }
  }
}
document.addEventListener("DOMContentLoaded", init);
document.addEventListener("keypress", handle_global_keydown);
