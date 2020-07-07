import SegmentActions  from "../../../../actions/SegmentActions";
// import CatToolActions  from "../../../../actions/CatToolActions";
import SegmentStore  from "../../../../stores/SegmentStore";
import TextUtils from "../../../../utils/textUtils";

let SearchUtils = {

    searchEnabled: true,
    searchParams: {
        search: 0
    },
    total: 0,
    searchResults: [],
    occurrencesList: [],
    searchResultsDictionary: {},
    featuredSearchResult: 0,
    searchSegmentsResult: [],

    /**
     * Called by the search component to execute search
     * @returns {boolean}
     */
    execFind: function(params) {

		$('section.currSearchSegment').removeClass('currSearchSegment');

		let searchSource = params.searchSource;
		if (searchSource !== '' && searchSource !== ' ') {
			this.searchParams.source = searchSource;
		} else {
			delete this.searchParams.source;
		}
		let searchTarget = params.searchTarget;
		if (searchTarget !== '' && searchTarget !== ' ')  {
			this.searchParams.target = searchTarget;
		} else {
			delete this.searchParams.target;
		}

		let selectStatus = params.selectStatus;
		if (selectStatus !== '') {
			this.searchParams.status = selectStatus ;
			this.searchParams.status = this.searchParams.status.toLowerCase();
		} else {
			delete this.searchParams.status;
		}

		let replaceTarget = params.replaceTarget;
		if (replaceTarget !== '') {
			this.searchParams.replace = replaceTarget;
		} else {
			delete this.searchParams.replace;
		}
		this.searchParams['match-case'] = params.matchCase;
		this.searchParams['exact-match'] = params.exactMatch;
		if (_.isUndefined(this.searchParams.source) && _.isUndefined(this.searchParams.target) && (this.searchParams.status == 'all')) {
			APP.alert({msg: 'Enter text in source or target input boxes<br /> or select a status.'});
			return false;
		}
		SegmentActions.disableTagLock();

		let p = this.searchParams;

		this.searchMode = (!_.isUndefined(p.source) && !_.isUndefined(p.target)) ? 'source&target' : 'normal';
		this.whereToFind = "";
		if ( this.searchMode === 'normal') {
		    if (!_.isUndefined(p.target)) {
                this.whereToFind = ".targetarea";
            } else if (!_.isUndefined(p.source)) {
                this.whereToFind = ".source";
            }
        }

        this.searchParams.searchMode = this.searchMode;

		let source = (p.source) ? TextUtils.htmlEncode(p.source) : '';
		let target = (p.target) ? TextUtils.htmlEncode(p.target) : '';
		let replace = (p.replace) ? p.replace : '';

		UI.body.addClass('searchActive');
		let makeSearchFn = () => {
            let dd = new Date();
            APP.doRequest({
                data: {
                    action: 'getSearch',
                    function: 'find',
                    job: config.id_job,
                    token: dd.getTime(),
                    password: config.password,
                    source: source,
                    target: target,
                    status: this.searchParams.status,
                    matchcase: this.searchParams['match-case'],
                    exactmatch: this.searchParams['exact-match'],
                    replace: replace,
                    revision_number: config.revisionNumber
                },
                success: function(d) {
                    SearchUtils.execFind_success(d);
                }
            });
        };
		//Save the current segment to not lose the translation
        try {
            let segment = SegmentStore.getSegmentByIdToJS(UI.currentSegmentId);
            if ( UI.translationIsToSaveBeforeClose( segment ) ) {
                UI.saveSegment(UI.currentSegment).then(() => {
                    makeSearchFn()
                });
            } else  {
                makeSearchFn();
            }
        } catch (e) {
            makeSearchFn();
        }


	},
    /**
     * Call in response to request getSearch
     * @param response
     */
    execFind_success: function(response) {
        this.resetSearch();
        this.searchSegmentsResult = response.segments;
        if ( response.segments.length > 0) {
            let searchObject = this.createSearchObject(response.segments);

            this.occurrencesList = _.clone(searchObject.occurrencesList);
            this.searchResultsDictionary = _.clone(searchObject.searchResultsDictionary);

            this.searchParams.current = searchObject.occurrencesList[0];

            CatToolActions.storeSearchResults({
                total: response.total,
                searchResults: searchObject.searchResults,
                occurrencesList: this.occurrencesList,
                searchResultsDictionary: _.clone(this.searchResultsDictionary),
                featuredSearchResult: 0,
            });
            SegmentActions.addSearchResultToSegments(this.occurrencesList, this.searchResultsDictionary ,0);
        } else {
            SegmentActions.removeSearchResultToSegments();
            this.resetSearch();
            CatToolActions.storeSearchResults({
                total: 0,
                searchResults: [],
                occurrencesList: [],
                searchResultsDictionary: {},
                featuredSearchResult: 0,
            });
        }
	},


    updateSearchObjectAfterReplace: function(segmentsResult) {
        this.searchSegmentsResult = segmentsResult ? segmentsResult : this.searchSegmentsResult;
        let searchObject = this.createSearchObject(this.searchSegmentsResult);
        this.occurrencesList = searchObject.occurrencesList;
        this.searchResultsDictionary = searchObject.searchResultsDictionary;
        return searchObject;
    },

    updateSearchObject: function() {
        let currentFeaturedSegment = this.occurrencesList[this.featuredSearchResult];
        let searchObject = this.createSearchObject(this.searchSegmentsResult);
        this.occurrencesList = searchObject.occurrencesList;
        this.searchResultsDictionary = searchObject.searchResultsDictionary;
        let newIndex = _.findIndex(this.occurrencesList, (item) => item === currentFeaturedSegment);
        if ( newIndex > -1 ) {
            this.featuredSearchResult = newIndex;
        } else {
            this.featuredSearchResult = this.featuredSearchResult + 1;
        }
        searchObject.featuredSearchResult = this.featuredSearchResult;
        return searchObject;
    },

    getMatchesInText: function(text, textToMatch, ignoreCase, isExactMatch) {
        textToMatch = TextUtils.escapeRegExp(textToMatch);
        let reg = new RegExp( '(' + textToMatch + ')', "g" + ignoreCase );
        if (isExactMatch) {
            reg = new RegExp( '\\b(' + textToMatch.replace( /\(/g, '\\(' ).replace( /\)/g, '\\)' ) + ')\\b', "g" + ignoreCase );
        }
        return text.matchAll( reg );
    },

    createSearchObject: function(segments) {
        let searchProgressiveIndex = 0;
        let occurrencesList = [], searchResultsDictionary = {};
        let searchResults = segments.map( (sid) => {
            let ignoreCase = (this.searchParams['match-case']) ? '' : 'i';
            let segment = SegmentStore.getSegmentByIdToJS(sid);
            let item = {id: sid, occurrences: []};
            if (segment) {
                if ( this.searchParams.searchMode === 'source&target' ) {

                    let {text : textSource, tagsIntervals: tagsIntervalsSource } = this.prepareTextToReplace(segment.decoded_source);
                    const matchesSource = this.getMatchesInText(textSource, this.searchParams.source, ignoreCase, this.searchParams['exact-match']);
                    let {text : textTarget, tagsIntervals: tagsIntervalsTarget} = this.prepareTextToReplace(segment.decoded_translation);
                    const matchesTarget = this.getMatchesInText(textTarget, this.searchParams.target, ignoreCase, this.searchParams['exact-match']);

                    let sourcesMatches = [], targetMatches = [];
                    for ( const match of matchesSource ) {
                        sourcesMatches.push( match );
                    }
                    for ( const match of matchesTarget ) {
                        targetMatches.push( match );
                    }
                    //Check if source and target has the same occurrences
                    let matches = (sourcesMatches.length > targetMatches.length) ? targetMatches : sourcesMatches;
                    let tagsIntervals = (sourcesMatches.length > targetMatches.length) ? tagsIntervalsTarget : tagsIntervalsSource
                    for ( const match of matches ) {
                        let intervalSpan = _.find(tagsIntervals, (item)=> match.index > item.start && match.index < item.end);
                        if ( !intervalSpan ) {
                            occurrencesList.push( sid );
                            item.occurrences.push( {matchPosition: match.index, searchProgressiveIndex: searchProgressiveIndex} );
                            searchProgressiveIndex++;
                        }
                    }

                } else {
                    if ( this.searchParams.source ) {

                        let {text, tagsIntervals} = this.prepareTextToReplace(segment.decoded_source);

                        const matchesSource = this.getMatchesInText(text, this.searchParams.source, ignoreCase, this.searchParams['exact-match']);
                        for ( const match of matchesSource ) {
                            let intervalSpan = _.find(tagsIntervals, (item)=> match.index > item.start && match.index < item.end);
                            if ( !intervalSpan ) {
                                occurrencesList.push( sid );
                                item.occurrences.push( {matchPosition: match.index, searchProgressiveIndex: searchProgressiveIndex} );
                                searchProgressiveIndex++;
                            }

                        }
                    } else if ( this.searchParams.target ) {
                        let {text, tagsIntervals} = this.prepareTextToReplace(segment.decoded_translation);
                        const matchesTarget = this.getMatchesInText(text, this.searchParams.target, ignoreCase, this.searchParams['exact-match']);
                        for ( const match of matchesTarget ) {
                            let intervalSpan = _.find(tagsIntervals, (item)=> match.index > item.start && match.index < item.end);
                            if ( !intervalSpan ) {
                                occurrencesList.push( sid );
                                item.occurrences.push( {matchPosition: match.index, searchProgressiveIndex: searchProgressiveIndex} );
                                searchProgressiveIndex++;
                            }

                        }
                    }
                }

            } else {
                searchProgressiveIndex++;
                occurrencesList.push(sid);
            }
            searchResultsDictionary[sid] = item;
            return item;
        });
        console.log("SearchResults", searchResults);
        console.log("occurrencesList", occurrencesList);
        console.log("searchResultsDictionary", searchResultsDictionary);
        return {
            searchResults: searchResults,
            occurrencesList: occurrencesList,
            searchResultsDictionary: searchResultsDictionary
        }
    },

    /**
     * Toggle the Search container
     * @param e
     */
	toggleSearch: function(e) {
		if (!this.searchEnabled) return;
        if (UI.body.hasClass('searchActive')) {
            CatToolActions.closeSearch();
        } else {
            e.preventDefault();
            CatToolActions.toggleSearch();
            // this.fixHeaderHeightChange();
        }
	},
    /**
     * Executes the replace all for segments if all the params are ok
     * @returns {boolean}
     */
    execReplaceAll: function(params) {
        // $('.search-display .numbers').text('No segments found');
        // this.applySearch();

        let searchSource = params.searchSource;
        if (searchSource !== '' && searchSource !== ' ' && searchSource !== '\'' && searchSource !== '"' ) {
            this.searchParams.source = searchSource;
        } else {
            delete this.searchParams.source;
        }

        let searchTarget = params.searchTarget;
        if (searchTarget !== '' && searchTarget !== ' '  && searchTarget !== '"')  {
            this.searchParams.target = searchTarget;
        } else {
            APP.alert({msg: 'You must specify the Target value to replace.'});
            delete this.searchParams.target;
            return false;
        }

        let replaceTarget =  params.replaceTarget;
        if ( replaceTarget !== '"')  {
            this.searchParams.replace = replaceTarget;
        } else {
            APP.alert({msg: 'You must specify the replacement value.'});
            delete this.searchParams.replace;
            return false;
        }

        if (params.selectStatus !== '' && params.selectStatus !== 'all') {
            this.searchParams.status = params.selectStatus;
            UI.body.attr('data-filter-status', params.selectStatus);
        } else {
            delete this.searchParams.status;
        }

        this.searchParams['match-case'] = params.matchCase;
        this.searchParams['exact-match'] = params.exactMatch;

        let p = this.searchParams;
        let source = (p.source) ? TextUtils.htmlEncode(p.source) : '';
        let target = (p.target) ? TextUtils.htmlEncode(p.target) : '';
        let replace = (p.replace) ? p.replace : '';
        let dd = new Date();

        APP.doRequest({
            data: {
                action: 'getSearch',
                function: 'replaceAll',
                job: config.id_job,
                token: dd.getTime(),
                password: config.password,
                source: source,
                target: target,
                status: p.status,
                matchcase: p['match-case'],
                exactmatch: p['exact-match'],
                replace: replace,
                revision_number: config.revisionNumber
            },
            success: function(d) {
                if(d.errors.length) {
                    APP.alert({msg: d.errors[0].message});
                    return false;
                }
                UI.unmountSegments();
                UI.render({
                    firstLoad: false
                });
            }
        });
    },

    updateFeaturedResult(value) {
        this.featuredSearchResult = value;
    },

    prepareTextToReplace(text) {
        var LTPLACEHOLDER = "##LESSTHAN##";
        var GTPLACEHOLDER = "##GREATERTHAN##";
        let spanArray = [];
        // text = text.replace(/\&gt;/g, '>').replace(/\&lt;/g, '<');
        // text = text.replace(/(&lt;[/]*(span|mark|a).*?&gt;)/g, function ( match, text ) {
        //     spanArray.push(text);
        //     return "$&";
        // });
        text = text.replace(/>/g, function (match, index) {
            return GTPLACEHOLDER;
        });
        text = text.replace(/</g, function (match, index) {
            return LTPLACEHOLDER;
        });
        let tagsIntervals = [];
        let matchFind = 0;
        let regGtp = new RegExp(GTPLACEHOLDER,'g');
        text = text.replace(regGtp, function (match, index) {
            let interval = {end: index + GTPLACEHOLDER.length};
            tagsIntervals.push(interval);
            matchFind++;
            return match;
        });
        matchFind = 0;
        let regLtp = new RegExp(LTPLACEHOLDER, 'g');
        text = text.replace(regLtp, function (match, index) {
            if ( tagsIntervals[matchFind] &&  tagsIntervals[matchFind].end) {
                tagsIntervals[matchFind].start = index;
            }
            matchFind++;
            return match;
        });
        return {
            text: text,
            tagsIntervals: tagsIntervals,
            tagsArray: spanArray
        }
    },

    restoreTextAfterReplace(text, tagsArray) {
        // text = text.replace(/(\$&)/g, function ( match, text ) {
        //     return tagsArray.shift();
        // });
        text = text.replace(/>/g, '&gt;').replace(/</g, '&lt;');
        //console.log('-- text3: ' + text);
        text = text.replace(/##GREATERTHAN##/g, '>').replace(/##LESSTHAN##/g, '<');
        return text;
    },

    markText: function(textToMark, isSource, sid) {

        if ( this.occurrencesList.indexOf(sid) === -1 ) return textToMark;
	    let reg;
	    const isCurrent = ( this.occurrencesList[this.featuredSearchResult] === sid );
	    const occurrences = this.searchResultsDictionary[sid].occurrences;
	    let params = this.searchParams;

        let searchMarker = 'searchMarker';
        let ignoreCase = (params['match-case']) ? '' : 'i';
        if ( this.searchMode === 'source&target' ) {
            let txt = (isSource) ? params.source : params.target;
            txt = TextUtils.escapeRegExp(txt.replace(/(\W)/gi, "\\$1"));
            reg = new RegExp('(' + TextUtils.htmlEncode(txt) + ')', "g" + ignoreCase);

        } else if ( (params.source && isSource) || (params.target && !isSource) ) {
	        let txt = params.source ? params.source : params.target ;
            txt = TextUtils.escapeRegExp(txt);
            let regTxt = txt.replace(/(\W)/gi, "\\$1");
            // regTxt = regTxt.replace(/\(/gi, "\\(").replace(/\)/gi, "\\)");

            reg = new RegExp('(' + TextUtils.htmlEncode(regTxt)+ ')', "g" + ignoreCase);

            if (params['exact-match'] ) {
                reg = new RegExp('\\b(' + TextUtils.htmlEncode(regTxt).replace(/\(/g, '\\(').replace(/\)/g, '\\)') + ')\\b', "g" + ignoreCase);
            }

            // Finding double spaces
            if (txt === "  ") {
                reg = new RegExp(/(&nbsp; )/, 'gi');
            }
        }

        let {text, tagsIntervals, tagsArray } = this.prepareTextToReplace(textToMark);

        let matchIndex = 0;
        text = text.replace(reg,  ( match, text, index ) => {
            let intervalSpan = _.find(tagsIntervals, (item)=> index > item.start && index < item.end);
            if ( !intervalSpan ) {
                let className = (isCurrent && occurrences[matchIndex] && occurrences[matchIndex].searchProgressiveIndex === this.featuredSearchResult) ? searchMarker + " currSearchItem" : searchMarker;
                matchIndex++;
                return '##LESSTHAN##mark class="' + className + '"##GREATERTHAN##' + match + '##LESSTHAN##/mark##GREATERTHAN##';
            } else {
                return match
            }
        });
        text = this.restoreTextAfterReplace(text, tagsArray);
        return text;
    },
    resetSearch: function() {
        this.searchResults = [];
        this.occurrencesList = [];
        this.searchResultsDictionary = {};
        this.featuredSearchResult = 0;
        this.searchSegmentsResult = [];
    },
    /**
     * Close search container
     */
    closeSearch : function() {
        this.resetSearch();
        CatToolActions.closeSubHeader();
        SegmentActions.removeSearchResultToSegments();

        CatToolActions.storeSearchResults({
            total: 0,
            searchResults: [],
            occurrencesList: [],
            searchResultsDictionary: {},
            featuredSearchResult: 0,
        });
    },
};

module.exports = SearchUtils;

