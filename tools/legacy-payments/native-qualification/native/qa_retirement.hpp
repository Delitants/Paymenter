// Fixed QA-only retirement policy. No native SQL adapter, issuer or entry point.
// Its guard must establish original creation/ownership, quiescence and current
// cleanup authority. Implementing a permissive guard does not supply that proof.
#pragma once
#include "reader_core.hpp"
#include <array>
#include <map>
#include <set>
namespace paymenter_qa {
class QaRetirementError:public std::runtime_error {
public:explicit QaRetirementError(const char* code):std::runtime_error(code){}
};
inline void retirement_require(bool ok,const char* code){if(!ok)throw QaRetirementError(code);}
struct RegisteredQaCase {std::string id,facts;};
struct QaRetirementRegistry {std::string run;std::vector<RegisteredQaCase> cases;std::array<std::string,2> definitions;};
class QaRetirementGuard {
public:
    virtual ~QaRetirementGuard(){}
    // Original table absence/create/schema custody, no triggers/native objects,
    // actual SDK writer retirement and bounded explicit cleanup approval. A
    // profile flag, unload declaration or supplied boolean is insufficient.
    virtual void current()const=0;
};
class QaRetirementDatabase {
public:
    virtual ~QaRetirementDatabase(){}
    // Only the two compiled QA names; never accept table/SQL selectors. The
    // future source adapter must hold both locks across archive/delete/drop,
    // verify each table empty at drop and use finite native I/O/lock deadlines.
    virtual void lock_qa_tables()=0;
    virtual std::array<std::string,2> definitions()=0;
    virtual ReadRows all_qa_rows()=0;
    virtual void erase_case(const std::string& run,const std::string& id,const std::string& facts)=0;
    virtual void drop_empty_qa_tables()=0;
    // Fresh native metadata read; a successful DROP response alone is not proof.
    virtual bool qa_tables_absent()=0;
    virtual void unlock_qa_tables()=0;
};
class QaRetirementArchive {
public:virtual ~QaRetirementArchive(){}
    virtual void retain(const std::string& original)=0;
    virtual std::string reload()=0;
};
struct QaRetirementResult {bool complete=false,archived=false;unsigned erase_attempts=0,erased_cases=0;bool drop_attempted=false;std::string blocker;};
inline std::string retirement_hex(const std::string& raw) {
    const char* alphabet="0123456789abcdef";std::string result;
    for(unsigned char c:raw){result+=alphabet[c>>4];result+=alphabet[c&15];}return result;
}
inline std::string retirement_original(const QaRetirementRegistry& registry,const std::array<std::string,2>& schema,const ReadRows& rows) {
    retirement_require(rows.size()<=32,"retirement-row-bound");
    std::string raw="paymenter-source-qa-retirement-archive-v1\nrun="+registry.run+"\n";
    for(size_t i=0;i<2;++i){retirement_require(schema[i].size()<=4096,"retirement-schema-bound");
        raw+=(i==0?"business_schema_hex=":"journal_schema_hex=")+retirement_hex(schema[i])+"\n";}
    for(const auto& row:rows){retirement_require(row.size()<=5,"retirement-row-bound");raw+="row_hex=";
        for(size_t i=0;i<row.size();++i){retirement_require(row[i].size()<=128,"retirement-cell-bound");if(i)raw+=",";raw+=retirement_hex(row[i]);}raw+="\n";}
    retirement_require(raw.size()<=65536,"retirement-archive-bound");return raw;
}
inline QaRetirementResult retire_qa_tables(const QaRetirementRegistry& registry,const QaRetirementGuard& guard,
                                          QaRetirementDatabase& db,QaRetirementArchive& archive) {
    QaRetirementResult result;bool lock_attempted=false;
    try {
        retirement_require(reader_hex(registry.run) && !registry.cases.empty() && registry.cases.size()<=5,"retirement-registry");
        std::map<std::string,std::string> cases;
        for(const auto& c:registry.cases)retirement_require(c.id.size()==2 && c.id[0]=='F' && c.id[1]>='1' && c.id[1]<='5' &&
            reader_hex(c.facts) && cases.emplace(c.id,c.facts).second,"retirement-registry");
        for(const auto& s:registry.definitions)retirement_require(!s.empty() && s.size()<=4096,"retirement-registry");
        guard.current();lock_attempted=true;db.lock_qa_tables();guard.current();
        const auto schema=db.definitions();const auto rows=db.all_qa_rows();guard.current();
        const auto original=retirement_original(registry,schema,rows);
        archive.retain(original);
        retirement_require(archive.reload()==original,"retirement-archive-verify");result.archived=true;
        guard.current();retirement_require(schema==registry.definitions,"retirement-schema-changed");
        std::set<std::pair<std::string,std::string>> keys;std::set<std::string> used;
        for(const auto& row:rows) {
            retirement_require(row.size()==5 && row[1]==registry.run && cases.count(row[2]) && row[4]==cases.at(row[2]),"retirement-unowned-row");
            retirement_require((row[0]=="business" && row[3]=="0.0100") || (row[0]=="journal" && row[3]==row[2]),"retirement-row-shape");
            retirement_require(keys.emplace(row[0],row[2]).second,"retirement-duplicate-row");used.insert(row[2]);
        }
        for(const auto& id:used){guard.current();retirement_require(archive.reload()==original,"retirement-archive-changed");
            ++result.erase_attempts;db.erase_case(registry.run,id,cases.at(id));++result.erased_cases;guard.current();}
        retirement_require(db.all_qa_rows().empty(),"retirement-remaining-rows");
        retirement_require(db.definitions()==registry.definitions,"retirement-schema-changed");
        guard.current();retirement_require(archive.reload()==original,"retirement-archive-changed");
        result.drop_attempted=true;db.drop_empty_qa_tables();guard.current();
        retirement_require(db.qa_tables_absent(),"retirement-table-still-present");
        retirement_require(archive.reload()==original,"retirement-archive-changed");guard.current();
        result.complete=true;
    }catch(const QaRetirementError& e){result.blocker=e.what();}
    catch(...){result.blocker="retirement-incomplete";}
    if(lock_attempted) {
        try{db.unlock_qa_tables();}catch(...){result.complete=false;result.blocker="retirement-unlock-incomplete";}
    }
    // Never restore rows, recreate a partially dropped table, delete paid native
    // records or compensate through a provider. The archive and held result are
    // retained for a separately scoped recovery if any stage fails.
    return result;
}
}
