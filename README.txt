This is the first public version of AUTODEF for PHP.

AUTODEF stands for "AUTOmata DEFinition" language, and it's a descriptive
text-based language that you can use to easily create an automata in your
favorite programming language. AUTODEF works as a kind of "wrapper" over
your programs, so you can leverage the full power of your language of
choice.

It works using a special parser and an accompanying library. The parser
takes the AUTODEF source, interspersed with the native code, and transforms
it into a native version linked with the library.

On compiled languages, it's time to start the appropriate compiler and then
you will have your automata ready to work. On interpreted languages, though,
just execute the code and you're set.

After the native program is created, you can edit it with your editor of choice
and tweak it a bit more, if you want, but remember that each time you make
changes to the master AUTODEF source, your changes will be overwritten. It's
better to keep two copies, one for the AUTODEF parser to work with, and the
one with your refinements. The you use copy/paste/adapt in order to
incorporate the changes into your twaked version.

The format of an AUTODEF source file is shown in the example below:

[AUTOMATA:name]
/*
  This is a comment - C-style comments are used all along the code, and are
mostly ignored, but in some parts they will also be included in the final
parsed code. Just use the compiler and check to see where and how are they
kept.
*/
// -- First, you must specify global settings for the parser
%ignore_case = false; // True to ignore case in tokens
%char_mode = true; // This parser reads tokens character by charecter
%utf8 = true; // Use UTF-8 as input encoding
%ignore_whitespace = false; // True to ignore whitespace between tokens
/*
  This is the token definition section. Tokens are defined using PCRE-like
regular expressions. In PHP they're copied verbatim. In other langueges,
they may be transformed or translated as appropriate. Tokens are always
in CAPITAL LETTERS.
*/
[TOKENS]
  // A single spacing char (space, newline, carriage return or tab)
  SPC = \s
  // Multiple spacing chars (at least one)
  SPCS = {SPC}+
  // Multiple or zero spacing chars
  SPCZ = {SPC}*
  // - Standard ALL-CAPS identifier, with digits and '-' as second chars. and
  // an optional '.' at the end
  IDENT = [A-Z][-A-Z\d]*\.? 
  // Command identifiers allow lowercase letters but in the first one
  COMMAND = [A-Za-z][-a-z\d]*
  // Single quoted string, with '\' as a escape character
  SQSTR = \'(?:\\.|[^\\\\\'])*?\'
  // Double quoted string, with '\' as a escape character
  DQSTR = \"(?:\\.|[^\\\\\"])*?\"
  // A decimal or integer number, no sign
  NUMBER = (?:\d+\.\d*|\.\d+|\d+)
  // An interval in the form <integer> ".." <integer>
  INTERVAL_CONST = (?:(\d+)\s*\.\.\s*(\d+))
[/TOKENS]
/*
  Global definitions go after the automata's own code. You can specify all the
support code you need here.
*/
[GLOBALS]
// This section must exists, even if empty
[/GLOBALS]
/*
  The transitions which make up the automata definition.
*/
[TRANSITIONS]
/* 
  Initial state is ALWAYS 0. Final states are marked with "(END)" after the
state name. A semicolon marks the end of the state name and the beggining of
the transitions.
  The code for a state must be indented. The first line marks the minimum
indentation (in the example, two spaces or more).
  There're many different transition types:
*/
0(END):
  //  If the token SPC is found in the entrance, transition to a state named
  // "newstate". A semicolon marks the end of the transition.
  SPC -> &newstate ;
[/TRANSITIONS]
/*
  Events section. The events are code executed in different times of the
automata execution. You can alter the automata flux in the events' code.

  There're 3 type of events for any given state:
  
  ENTER - Fired when the automata enters this state from another.
  REPEAT - Fired when the automata entres this state from the same one.
  EXIT - Fired before the automata goes to a *new* state.

  Events are defined with this syntax:
  
  <event> = <state_name> "." <event_type> "{" <native_code> "}" ;
  <state_name> = IDENTIFIER | NUMBER ;
  <event_type> = "ENTER" | "REPEAT" | "EXIT" ;
  <native_code> = NATIVE_CODE ;
*/
[EVENTS]
[/EVENTS]
// -- The end of the automata definition. Everything outside of this
// block is ignored.
[/AUTOMATA]